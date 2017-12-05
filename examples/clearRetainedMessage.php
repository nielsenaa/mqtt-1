<?php

declare(strict_types=1);

use unreal4u\MQTT\Application\Message;
use unreal4u\MQTT\Application\SimplePayload;
use unreal4u\MQTT\Client;
use unreal4u\MQTT\Protocol\Connect;
use unreal4u\MQTT\Protocol\Connect\Parameters;
use unreal4u\MQTT\Protocol\Publish;

include __DIR__ . '/00.basics.php';

$connectionParameters = new Parameters('publishSomething');
$connectionParameters->setUsername('testuser');
$connectionParameters->setPassword('userpass');

$connect = new Connect();
$connect->setConnectionParameters($connectionParameters);

$client = new Client();
$client->sendData($connect);

$now = new \DateTimeImmutable('now');

if ($client->isConnected()) {
    $message = new Message();
    $message->setTopicName(COMMON_TOPICNAME);
    $message->shouldRetain(true);
    $message->setPayload(new SimplePayload(''));
    $publish = new Publish();
    $publish->setMessage($message);
    $client->sendData($publish);
    echo 'Cleared retained message';
}
echo PHP_EOL;
