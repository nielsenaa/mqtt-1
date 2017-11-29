<?php

declare(strict_types=1);

namespace unreal4u\MQTT;

use Psr\Log\LoggerInterface;
use unreal4u\MQTT\Exceptions\NotConnected;
use unreal4u\MQTT\Exceptions\ServerClosedConnection;
use unreal4u\MQTT\Internals\ReadableContentInterface;
use unreal4u\MQTT\Internals\WritableContentInterface;
use unreal4u\MQTT\Protocol\Connect;
use unreal4u\MQTT\Protocol\Disconnect;

final class Client
{
    /**
     * Where all the magic happens
     * @var Resource
     */
    public $socket;

    /**
     * Logs all activity
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Fast way to know whether we are connected or not
     * @var bool
     */
    private $isConnected = false;

    /**
     * Annotates the last time there was known to be communication with the MQTT server
     * @var \DateTimeImmutable
     */
    private $lastCommunication;

    /**
     * Internal holder of connection parameters
     * @var Connect\Parameters
     */
    private $connectionParameters;

    public function __construct(LoggerInterface $logger = null)
    {
        if ($logger === null) {
            $logger = new DummyLogger();
        }

        $this->logger = $logger;
    }

    /**
     * Be gentle and disconnect gracefully should this class be destroyed
     *
     * @throws \unreal4u\MQTT\Exceptions\MessageTooBig
     * @throws \unreal4u\MQTT\Exceptions\InvalidMethod
     * @throws \unreal4u\MQTT\Exceptions\NotConnected
     * @throws \unreal4u\MQTT\Exceptions\Connect\NoConnectionParametersDefined
     * @throws \unreal4u\MQTT\Exceptions\ServerClosedConnection
     */
    public function __destruct()
    {
        if ($this->socket !== null) {
            $this->logger->info('Currently connected to broker, disconnecting from it');

            $this->sendData(new Disconnect($this->logger));
        }
    }

    /**
     * Allows us to read an arbitrary number of bytes from the socket connection
     *
     * @param int $bytes
     * @return string
     */
    public function readSocketData(int $bytes): string
    {
        $this->logger->debug('Reading bytes from socket', ['numberOfBytes' => $bytes]);
        return fread($this->socket, $bytes);
    }

    /**
     * The first 4 bytes will _always_ contain basic information with which we'll know what to do afterwards
     * @return string
     */
    public function readSocketHeader(): string
    {
        $this->logger->debug('Reading header from response');
        return $this->readSocketData(4);
    }

    /**
     * Sends the data to the socket and waits for an answer from the broker
     *
     * @param WritableContentInterface $object
     * @return string
     * @throws \unreal4u\MQTT\Exceptions\ServerClosedConnection
     * @throws \unreal4u\MQTT\Exceptions\NotConnected
     */
    public function sendSocketData(WritableContentInterface $object): string
    {
        if ($this->socket === null) {
            $this->logger->alert('Not connected before sending data');
            throw new NotConnected('Please connect before performing any other request');
        }

        $writableString = $object->createSendableMessage();
        $sizeOfString = \strlen($writableString);
        $writtenBytes = fwrite($this->socket, $writableString, $sizeOfString);
        if ($writtenBytes !== $sizeOfString) {
            $this->logger->error('Written bytes do NOT correspond with size of string!', [
                'writtenBytes' => $writtenBytes,
                'sizeOfString' => $sizeOfString,
            ]);

            throw new ServerClosedConnection('The server may have disconnected the current client');
        }
        $this->logger->debug('Sending data to socket', [
            'writtenBytes' => $writtenBytes,
            'sizeOfString' => $sizeOfString,
        ]);

        if ($object->shouldExpectAnswer() === true) {
            return $this->readSocketHeader();
        }

        return '';
    }

    /**
     * Special handling of the connect part: create the socket
     *
     * @param Connect $connection
     * @return bool
     * @throws \unreal4u\MQTT\Exceptions\Connect\NoConnectionParametersDefined
     */
    private function generateSocketConnection(Connect $connection): bool
    {
        $this->logger->debug('Creating socket connection');
        $this->connectionParameters = $connection->getConnectionParameters();
        $this->socket = stream_socket_client(
            $this->connectionParameters->getConnectionUrl(),
            $errorNumber,
            $errorString,
            60,
            STREAM_CLIENT_CONNECT
        );

        stream_set_timeout($this->socket, (int)floor($this->connectionParameters->getKeepAlivePeriod() * 1.5));
        $this->setBlocking(true);

        $this->logger->debug('Created socket connection successfully, continuing', stream_get_meta_data($this->socket));
        return true;
    }

    /**
     * Defines whether the following requests should act sync- or asynchronously
     *
     * @param bool $newStatus
     * @return Client
     */
    public function setBlocking(bool $newStatus): Client
    {
        $this->logger->debug('Setting new blocking status', ['newStatus' => $newStatus]);
        stream_set_blocking($this->socket, $newStatus);
        return $this;
    }

    /**
     * Prepares and sends the given request to the MQTT broker
     *
     * @param WritableContentInterface $object
     * @return ReadableContentInterface
     * @throws \unreal4u\MQTT\Exceptions\ServerClosedConnection
     * @throws \unreal4u\MQTT\Exceptions\Connect\NoConnectionParametersDefined
     * @throws \unreal4u\MQTT\Exceptions\NotConnected
     * @throws \unreal4u\MQTT\Exceptions\InvalidMethod
     */
    public function sendData(WritableContentInterface $object): ReadableContentInterface
    {
        $currentObject = \get_class($object);
        $this->logger->debug('Validating object', ['object' => $currentObject]);

        if ($object instanceof Connect) {
            $this->generateSocketConnection($object);
        }

        $this->logger->info('About to send data', ['object' => $currentObject]);
        $readableContent = $object->expectAnswer($this->sendSocketData($object));
        /*
         * Some objects must perform certain actions on the connection, for example:
         * - Connack must set the connected bit
         * - PingResp must reset the internal last-communication datetime
         */
        $this->logger->debug('Executing special actions for this object', [
            'originObject' => $currentObject,
            'responseObject' => \get_class($readableContent),
        ]);
        $readableContent->performSpecialActions($this);

        return $readableContent;
    }

    /**
     * Will let us know if we are approaching the time limit in which the broker will disconnect us
     *
     * As per protocol, the broker will disconnect us after 1.5 times the configured keepAlive period, so it is safe to
     * assume that between the keep alive period and that same period * 1.5 we must send in a PINGREQ packet.
     *
     * @return bool
     */
    public function needsCommunication(): bool
    {
        $secondsDifference = (new \DateTime('now'))->getTimestamp() - $this->lastCommunication->getTimestamp();
        $this->logger->debug('Checking time difference', ['secondsDifference' => $secondsDifference]);

        return
            $this->isConnected() &&
            $this->connectionParameters->getKeepAlivePeriod() > 0 &&
            $secondsDifference >= $this->connectionParameters->getKeepAlivePeriod();
    }

    /**
     * Updates the internal counter to know when was the last known communication with the MQTT broker
     *
     * This will create a timestamp with support for microseconds
     * @see https://gist.github.com/graste/47a4a6433dfe0acf64b7
     *
     * @return Client
     */
    public function updateLastCommunication(): Client
    {
        $lastCommunication = null;
        if ($this->lastCommunication !== null) {
            $lastCommunication = $this->lastCommunication->format('Y-m-d H:i:s.u');
        }
        // "now" does not support microseconds, so create the timestamp with a format that does
        $this->lastCommunication = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)));
        $this->logger->debug('Updating internal last communication timestamp', [
            'previousValue' => $lastCommunication,
            'currentValue' => $this->lastCommunication->format('Y-m-d H:i:s.u'),
        ]);
        return $this;
    }

    /**
     * Sets an easy bit for us to know whether we are connected to an MQTT broker or not
     *
     * @param bool $isConnected
     * @return Client
     */
    public function setConnected(bool $isConnected): Client
    {
        $this->logger->debug('Setting internal connected property', ['connected' => $isConnected]);
        $this->isConnected = $isConnected;
        if ($this->isConnected() === false) {
            $this->socket = null;
        }

        return $this;
    }

    /**
     * Will return the status of the connection in an easy way
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->isConnected;
    }
}