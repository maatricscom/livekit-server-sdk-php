<?php

declare(strict_types=1);

namespace LiveKit\Tests\Services;

use LiveKit\Http\DialTimeout;
use LiveKit\Options\AcceptWhatsAppCallOptions;
use LiveKit\Options\ConnectTwilioCallOptions;
use LiveKit\Options\ConnectWhatsAppCallOptions;
use LiveKit\Options\DialWhatsAppCallOptions;
use LiveKit\Proto\AcceptWhatsAppCallRequest;
use LiveKit\Proto\AcceptWhatsAppCallResponse;
use LiveKit\Proto\ConnectTwilioCallRequest;
use LiveKit\Proto\ConnectTwilioCallRequest\TwilioCallDirection;
use LiveKit\Proto\ConnectTwilioCallResponse;
use LiveKit\Proto\ConnectWhatsAppCallRequest;
use LiveKit\Proto\ConnectWhatsAppCallResponse;
use LiveKit\Proto\DialWhatsAppCallRequest;
use LiveKit\Proto\DialWhatsAppCallResponse;
use LiveKit\Proto\DisconnectWhatsAppCallRequest;
use LiveKit\Proto\DisconnectWhatsAppCallRequest\DisconnectReason;
use LiveKit\Proto\DisconnectWhatsAppCallResponse;
use LiveKit\Proto\RoomAgentDispatch;
use LiveKit\Proto\SessionDescription;
use LiveKit\Services\ConnectorClient;
use LiveKit\Tests\Support\TwirpTestCase;

final class ConnectorClientTest extends TwirpTestCase
{
    private function client(): ConnectorClient
    {
        return new ConnectorClient(self::HOST, self::API_KEY, self::API_SECRET, httpClient: $this->http);
    }

    private function sdp(string $type = 'offer'): SessionDescription
    {
        return (new SessionDescription())->setType($type)->setSdp('v=0\r\no=- 0 0 IN IP4 127.0.0.1\r\n');
    }

    public function testDialWhatsAppCallSendsTheMetaCredentialsAndReturnsTheCall(): void
    {
        $expected = new DialWhatsAppCallResponse();
        $expected->setWhatsappCallId('WACID_abc');
        $expected->setRoomName('wa-room');

        $this->http->pushResponse($this->protoResponse($expected));

        $agent = (new RoomAgentDispatch())->setAgentName('support-bot');

        $response = $this->client()->dialWhatsAppCall(
            'PN_123',
            '+15551234567',
            'meta-api-key',
            '23.0',
            new DialWhatsAppCallOptions(
                bizOpaqueCallbackData: 'trace-42',
                roomName: 'wa-room',
                agents: [$agent],
                participantIdentity: 'wa-caller',
                participantName: 'WhatsApp Caller',
                participantMetadata: '{"plan":"pro"}',
                participantAttributes: ['tier' => 'gold'],
                destinationCountry: 'TR',
                ringingTimeout: 45,
            ),
        );

        self::assertSame('WACID_abc', $response->getWhatsappCallId());
        self::assertSame('wa-room', $response->getRoomName());

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'Connector', 'DialWhatsAppCall');
        $this->assertVideoGrant(['roomCreate' => true], $request);

        $sent = $this->decodeRequest(DialWhatsAppCallRequest::class);
        self::assertSame('PN_123', $sent->getWhatsappPhoneNumberId());
        self::assertSame('+15551234567', $sent->getWhatsappToPhoneNumber());
        self::assertSame('meta-api-key', $sent->getWhatsappApiKey());
        self::assertSame('23.0', $sent->getWhatsappCloudApiVersion());
        self::assertSame('trace-42', $sent->getWhatsappBizOpaqueCallbackData());
        self::assertSame('wa-room', $sent->getRoomName());
        self::assertSame('wa-caller', $sent->getParticipantIdentity());
        self::assertSame('WhatsApp Caller', $sent->getParticipantName());
        self::assertSame('{"plan":"pro"}', $sent->getParticipantMetadata());
        self::assertSame('TR', $sent->getDestinationCountry());
        self::assertSame(['tier' => 'gold'], iterator_to_array($sent->getParticipantAttributes()));
        self::assertSame(45, (int) $sent->getRingingTimeout()?->getSeconds());

        // RepeatedField's iterator carries no generic value type, so an element
        // reads as mixed. Narrow it the same way the service clients do.
        $agentNames = [];
        foreach ($sent->getAgents() as $sentAgent) {
            self::assertInstanceOf(RoomAgentDispatch::class, $sentAgent);
            $agentNames[] = $sentAgent->getAgentName();
        }

        self::assertSame(['support-bot'], $agentNames);
    }

    public function testDialWhatsAppCallLeavesEveryOptionalFieldUnsetWithoutOptions(): void
    {
        $this->http->pushResponse($this->protoResponse(new DialWhatsAppCallResponse()));

        $this->client()->dialWhatsAppCall('PN_123', '+15551234567', 'meta-api-key', '23.0');

        $sent = $this->decodeRequest(DialWhatsAppCallRequest::class);
        self::assertSame('', $sent->getRoomName());
        self::assertSame('', $sent->getParticipantIdentity());
        self::assertSame('', $sent->getDestinationCountry());
        self::assertNull($sent->getRingingTimeout(), 'An unset Duration must stay absent, not become zero.');
        self::assertCount(0, $sent->getAgents());
    }

    public function testAcceptWhatsAppCallSendsTheOfferFromMeta(): void
    {
        $expected = new AcceptWhatsAppCallResponse();
        $expected->setRoomName('wa-room');

        $this->http->pushResponse($this->protoResponse($expected));

        $response = $this->client()->acceptWhatsAppCall(
            'PN_123',
            'meta-api-key',
            '23.0',
            'WACID_abc',
            $this->sdp(),
            new AcceptWhatsAppCallOptions(roomName: 'wa-room', participantIdentity: 'wa-caller'),
        );

        self::assertSame('wa-room', $response->getRoomName());

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'Connector', 'AcceptWhatsAppCall');
        $this->assertVideoGrant(['roomCreate' => true], $request);

        $sent = $this->decodeRequest(AcceptWhatsAppCallRequest::class);
        self::assertSame('WACID_abc', $sent->getWhatsappCallId());
        self::assertSame('offer', $sent->getSdp()?->getType());
        self::assertSame('wa-room', $sent->getRoomName());
    }

    public function testAcceptWhatsAppCallKeepsTheDefaultTimeoutWhenItDoesNotWait(): void
    {
        $this->http->pushResponse($this->protoResponse(new AcceptWhatsAppCallResponse()));

        $this->client()->acceptWhatsAppCall('PN_123', 'k', '23.0', 'WACID_abc', $this->sdp());

        // Nothing is waiting on a ringing phone, so this is an ordinary request.
        self::assertSame('10000', $this->http->lastRequest()->getHeaderLine('X-Twirp-Timeout-Ms'));
    }

    public function testAcceptWhatsAppCallOutlastsTheRingWindowWhenItWaits(): void
    {
        $this->http->pushResponse($this->protoResponse(new AcceptWhatsAppCallResponse()));

        $this->client()->acceptWhatsAppCall(
            'PN_123',
            'k',
            '23.0',
            'WACID_abc',
            $this->sdp(),
            new AcceptWhatsAppCallOptions(waitUntilAnswered: true),
        );

        $expected = DialTimeout::DEFAULT_RINGING_TIMEOUT_SECONDS + DialTimeout::RINGING_TIMEOUT_MARGIN_SECONDS;

        self::assertSame(
            (string) ($expected * 1000),
            $this->http->lastRequest()->getHeaderLine('X-Twirp-Timeout-Ms'),
            'A request that waits on an answer has to outlast the ring window.'
        );

        $sent = $this->decodeRequest(AcceptWhatsAppCallRequest::class);
        self::assertTrue($sent->getWaitUntilAnswered());
        self::assertSame(
            DialTimeout::DEFAULT_RINGING_TIMEOUT_SECONDS,
            (int) $sent->getRingingTimeout()?->getSeconds(),
            'The ring window is pinned rather than left to the server default.'
        );
    }

    public function testAcceptWhatsAppCallRaisesATooShortCallerTimeoutToTheFloor(): void
    {
        $this->http->pushResponse($this->protoResponse(new AcceptWhatsAppCallResponse()));

        $this->client()->acceptWhatsAppCall(
            'PN_123',
            'k',
            '23.0',
            'WACID_abc',
            $this->sdp(),
            new AcceptWhatsAppCallOptions(ringingTimeout: 60, waitUntilAnswered: true, timeout: 5),
        );

        self::assertSame(
            (string) ((60 + DialTimeout::RINGING_TIMEOUT_MARGIN_SECONDS) * 1000),
            $this->http->lastRequest()->getHeaderLine('X-Twirp-Timeout-Ms'),
            'A 5s timeout would abort 57s before the phone stopped ringing.'
        );
    }

    public function testAcceptWhatsAppCallHonoursALongerCallerTimeout(): void
    {
        $this->http->pushResponse($this->protoResponse(new AcceptWhatsAppCallResponse()));

        $this->client()->acceptWhatsAppCall(
            'PN_123',
            'k',
            '23.0',
            'WACID_abc',
            $this->sdp(),
            new AcceptWhatsAppCallOptions(waitUntilAnswered: true, timeout: 120),
        );

        self::assertSame('120000', $this->http->lastRequest()->getHeaderLine('X-Twirp-Timeout-Ms'));
    }

    public function testConnectWhatsAppCallSendsTheAnswer(): void
    {
        $this->http->pushResponse($this->protoResponse(new ConnectWhatsAppCallResponse()));

        $this->client()->connectWhatsAppCall('WACID_abc', $this->sdp('answer'));

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'Connector', 'ConnectWhatsAppCall');
        $this->assertVideoGrant(['roomCreate' => true], $request);
        self::assertSame('10000', $request->getHeaderLine('X-Twirp-Timeout-Ms'));

        $sent = $this->decodeRequest(ConnectWhatsAppCallRequest::class);
        self::assertSame('WACID_abc', $sent->getWhatsappCallId());
        self::assertSame('answer', $sent->getSdp()?->getType());
        self::assertFalse($sent->getWaitUntilAnswered());
    }

    public function testConnectWhatsAppCallOutlastsTheRingWindowWhenItWaits(): void
    {
        $this->http->pushResponse($this->protoResponse(new ConnectWhatsAppCallResponse()));

        $this->client()->connectWhatsAppCall(
            'WACID_abc',
            $this->sdp('answer'),
            new ConnectWhatsAppCallOptions(waitUntilAnswered: true),
        );

        $expected = DialTimeout::DEFAULT_RINGING_TIMEOUT_SECONDS + DialTimeout::RINGING_TIMEOUT_MARGIN_SECONDS;

        self::assertSame((string) ($expected * 1000), $this->http->lastRequest()->getHeaderLine('X-Twirp-Timeout-Ms'));
        self::assertTrue($this->decodeRequest(ConnectWhatsAppCallRequest::class)->getWaitUntilAnswered());
    }

    public function testDisconnectWhatsAppCallDefaultsTheReasonToTheServer(): void
    {
        $this->http->pushResponse($this->protoResponse(new DisconnectWhatsAppCallResponse()));

        $this->client()->disconnectWhatsAppCall('WACID_abc', 'meta-api-key');

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'Connector', 'DisconnectWhatsAppCall');
        $this->assertVideoGrant(['roomCreate' => true], $request);

        $sent = $this->decodeRequest(DisconnectWhatsAppCallRequest::class);
        self::assertSame('WACID_abc', $sent->getWhatsappCallId());
        self::assertSame('meta-api-key', $sent->getWhatsappApiKey());
        self::assertSame(DisconnectReason::BUSINESS_INITIATED, $sent->getDisconnectReason());
    }

    public function testDisconnectWhatsAppCallSendsAnExplicitReason(): void
    {
        $this->http->pushResponse($this->protoResponse(new DisconnectWhatsAppCallResponse()));

        $this->client()->disconnectWhatsAppCall('WACID_abc', '', DisconnectReason::USER_INITIATED);

        $sent = $this->decodeRequest(DisconnectWhatsAppCallRequest::class);
        self::assertSame(DisconnectReason::USER_INITIATED, $sent->getDisconnectReason());
    }

    public function testConnectTwilioCallReturnsTheMediaStreamUrl(): void
    {
        $expected = new ConnectTwilioCallResponse();
        $expected->setConnectUrl('wss://twilio.livekit.cloud/media');

        $this->http->pushResponse($this->protoResponse($expected));

        $response = $this->client()->connectTwilioCall(
            TwilioCallDirection::TWILIO_CALL_DIRECTION_OUTBOUND,
            'twilio-room',
            new ConnectTwilioCallOptions(
                participantIdentity: 'twilio-caller',
                participantAttributes: ['source' => 'twilio'],
                destinationCountry: 'DE',
            ),
        );

        self::assertSame('wss://twilio.livekit.cloud/media', $response->getConnectUrl());

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'Connector', 'ConnectTwilioCall');
        $this->assertVideoGrant(['roomCreate' => true], $request);

        $sent = $this->decodeRequest(ConnectTwilioCallRequest::class);
        self::assertSame(TwilioCallDirection::TWILIO_CALL_DIRECTION_OUTBOUND, $sent->getTwilioCallDirection());
        self::assertSame('twilio-room', $sent->getRoomName());
        self::assertSame('twilio-caller', $sent->getParticipantIdentity());
        self::assertSame('DE', $sent->getDestinationCountry());
        self::assertSame(['source' => 'twilio'], iterator_to_array($sent->getParticipantAttributes()));
    }

    public function testEveryRpcAsksForRoomCreateAndNothingElse(): void
    {
        // A connector call ends in a participant joining a room that may not exist
        // yet, which is all roomCreate is for. Anything wider would hand a token
        // with room admin rights to a webhook handler.
        $calls = [
            fn () => $this->client()->dialWhatsAppCall('PN', '+1', 'k', '23.0'),
            fn () => $this->client()->acceptWhatsAppCall('PN', 'k', '23.0', 'WACID', $this->sdp()),
            fn () => $this->client()->connectWhatsAppCall('WACID', $this->sdp()),
            fn () => $this->client()->disconnectWhatsAppCall('WACID', 'k'),
            fn () => $this->client()->connectTwilioCall(TwilioCallDirection::TWILIO_CALL_DIRECTION_INBOUND, 'r'),
        ];

        foreach ($calls as $call) {
            $this->http->pushResponse($this->protoResponse(new DialWhatsAppCallResponse()));
            $call();

            $request = $this->http->lastRequest();
            $this->assertVideoGrant(['roomCreate' => true], $request);
            $this->assertNoSipGrant($request);
        }

        self::assertSame(5, $this->http->requestCount());
    }
}
