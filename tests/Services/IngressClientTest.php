<?php

declare(strict_types=1);

namespace LiveKit\Tests\Services;

use LiveKit\Options\CreateIngressOptions;
use LiveKit\Proto\CreateIngressRequest;
use LiveKit\Proto\IngressAudioEncodingPreset;
use LiveKit\Proto\IngressAudioOptions;
use LiveKit\Proto\IngressInfo;
use LiveKit\Proto\IngressInput;
use LiveKit\Services\IngressClient;
use LiveKit\Tests\Support\TwirpTestCase;

final class IngressClientTest extends TwirpTestCase
{
    public function testCreateIngressPostsRequestAndReturnsInfo(): void
    {
        $expected = new IngressInfo();
        $expected->setIngressId('IN_abc123');
        $expected->setRoomName('my-room');
        $expected->setUrl('rtmp://test.livekit.cloud/x');

        $this->http->pushResponse($this->protoResponse($expected));

        $client = new IngressClient(
            self::HOST,
            self::API_KEY,
            self::API_SECRET,
            httpClient: $this->http,
        );

        $audio = new IngressAudioOptions();
        $audio->setName('audio');
        $audio->setPreset(IngressAudioEncodingPreset::OPUS_MONO_64KBS);

        $info = $client->createIngress(new CreateIngressOptions(
            inputType: IngressInput::RTMP_INPUT,
            name: 'my-ingress',
            roomName: 'my-room',
            participantIdentity: 'ingress-bot',
            participantName: 'Ingress Bot',
            participantMetadata: '{"src":"obs"}',
            enableTranscoding: true,
            enabled: false,
            audio: $audio,
        ));

        self::assertSame('IN_abc123', $info->getIngressId());
        self::assertSame('my-room', $info->getRoomName());

        self::assertSame(1, $this->http->requestCount());
        $request = $this->http->lastRequest();

        $this->assertTwirpRequest($request, 'Ingress', 'CreateIngress');

        $sent = $this->decodeRequest(CreateIngressRequest::class);
        self::assertSame(IngressInput::RTMP_INPUT, $sent->getInputType());
        self::assertSame('my-ingress', $sent->getName());
        self::assertSame('my-room', $sent->getRoomName());
        self::assertSame('ingress-bot', $sent->getParticipantIdentity());
        self::assertSame('Ingress Bot', $sent->getParticipantName());
        self::assertSame('{"src":"obs"}', $sent->getParticipantMetadata());
        self::assertSame('', $sent->getUrl());
        self::assertTrue($sent->hasEnableTranscoding());
        self::assertTrue($sent->getEnableTranscoding());
        self::assertTrue($sent->hasEnabled());
        self::assertFalse($sent->getEnabled());
        self::assertNotNull($sent->getAudio());
        self::assertSame('audio', $sent->getAudio()->getName());
        self::assertSame(IngressAudioEncodingPreset::OPUS_MONO_64KBS, $sent->getAudio()->getPreset());
        self::assertNull($sent->getVideo());

        $this->assertVideoGrant(['ingressAdmin' => true], $request);

        $claims = $this->claims($request);
        self::assertSame(self::API_KEY, $claims['iss']);
    }
}
