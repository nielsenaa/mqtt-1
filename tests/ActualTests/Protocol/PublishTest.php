<?php

declare(strict_types=1);

namespace tests\unreal4u\MQTT;

use PHPUnit\Framework\TestCase;
use unreal4u\MQTT\Application\EmptyReadableResponse;
use unreal4u\MQTT\Application\Message;
use unreal4u\MQTT\Application\SimplePayload;
use unreal4u\MQTT\Protocol\PubAck;
use unreal4u\MQTT\Protocol\Publish;

class PublishTest extends TestCase
{
    /**
     * @var Publish
     */
    private $publish;

    /**
     * @var Message
     */
    private $message;

    protected function setUp()
    {
        parent::setUp();
        $this->publish = new Publish();

        $this->message = new Message();
        $this->message->setPayload(new SimplePayload('Hello test world!'));
    }

    protected function tearDown()
    {
        parent::tearDown();
        $this->publish = null;
    }

    public function test_throwExceptionNoMessageProvided()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->publish->createVariableHeader();
    }

    public function test_publishBasicMessage()
    {
        $this->publish->setMessage($this->message);
        $variableHeader = $this->publish->createVariableHeader();
        $this->assertSame('AAA=', base64_encode($variableHeader));
    }

    public function test_PublishComplexMessage()
    {
        $this->message->setQoSLevel(1);
        $this->message->shouldRetain(true);

        $this->publish->setMessage($this->message);
        $variableHeader = $this->publish->createVariableHeader();
        $this->assertSame('AAAAAQ==', base64_encode($variableHeader));
    }

    public function test_NoAnswerRequired()
    {
        $this->publish->setMessage($this->message);
        $this->assertFalse($this->publish->shouldExpectAnswer());
    }

    public function test_AnswerRequired()
    {
        $this->message->setQoSLevel(1);
        $this->publish->setMessage($this->message);
        $this->assertTrue($this->publish->shouldExpectAnswer());
    }

    public function test_emptyExpectedAnswer()
    {
        $this->publish->setMessage($this->message);
        $answer = $this->publish->expectAnswer('000');
        $this->assertInstanceOf(EmptyReadableResponse::class, $answer);
    }

    public function test_QoSLevel1ExpectedAnswer()
    {
        $this->message->setQoSLevel(1);
        $this->publish->setMessage($this->message);
        $this->publish->createVariableHeader();
        /** @var PubAck $answer */
        $answer = $this->publish->expectAnswer(base64_decode('QAIAAQ=='));
        $this->assertInstanceOf(PubAck::class, $answer);
        $this->assertSame($answer->packetIdentifier, $this->publish->packetIdentifier);
    }
}