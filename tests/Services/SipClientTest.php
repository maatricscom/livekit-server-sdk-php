<?php

declare(strict_types=1);

namespace LiveKit\Tests\Services;

use LiveKit\Options\CreateSipInboundTrunkOptions;
use LiveKit\Options\CreateSipOutboundTrunkOptions;
use LiveKit\Options\SipInboundTrunkUpdateOptions;
use LiveKit\Proto\CreateSIPInboundTrunkRequest;
use LiveKit\Proto\CreateSIPOutboundTrunkRequest;
use LiveKit\Proto\ListUpdate;
use LiveKit\Proto\SIPHeaderOptions;
use LiveKit\Proto\SIPInboundTrunkInfo;
use LiveKit\Proto\SIPOutboundTrunkInfo;
use LiveKit\Proto\SIPTransport;
use LiveKit\Proto\UpdateSIPInboundTrunkRequest;
use LiveKit\Proto\UpdateSIPOutboundTrunkRequest;
use LiveKit\Services\SipClient;
use LiveKit\Tests\Support\TwirpTestCase;

final class SipClientTest extends TwirpTestCase
{
    private SipClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $psr17 = $this->psr17();
        $this->client = new SipClient(
            self::HOST,
            self::API_KEY,
            self::API_SECRET,
            null,
            $this->http,
            $psr17,
            $psr17,
        );
    }

    public function testCreateSipInboundTrunk(): void
    {
        $this->http->pushResponse($this->protoResponse(
            (new SIPInboundTrunkInfo())
                ->setSipTrunkId('ST_inbound')
                ->setName('main'),
        ));

        $trunk = $this->client->createSipInboundTrunk(
            'main',
            ['+15105550100'],
            new CreateSipInboundTrunkOptions(
                metadata: 'inbound-trunk',
                allowedAddresses: ['1.2.3.4/32'],
                allowedNumbers: ['+15105550199'],
                authUsername: 'sip-user',
                authPassword: 'sip-pass',
                authRealm: 'example.com',
                headers: ['X-Lk' => 'yes'],
                headersToAttributes: ['X-Lk' => 'attr.lk'],
                attributesToHeaders: ['attr.out' => 'X-Out'],
                includeHeaders: SIPHeaderOptions::SIP_X_HEADERS,
                krispEnabled: true,
                ringingTimeout: 25,
                maxCallDuration: 600,
            ),
        );

        // Transport envelope.
        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'CreateSIPInboundTrunk');

        // Grants in the minted JWT: sip.admin, plus a video grant that is present
        // but carries no permissions. assertVideoGrant([]) means present-and-empty,
        // which is the shape the SDK emits for a set-but-empty grant; the absent
        // case would be assertNoVideoGrant().
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);
        self::assertSame(self::API_KEY, $this->claims($request)['iss']);

        // The bytes that actually went on the wire.
        $sentRequest = $this->decodeRequest(CreateSIPInboundTrunkRequest::class);
        $sent = $sentRequest->getTrunk();
        self::assertInstanceOf(SIPInboundTrunkInfo::class, $sent);
        self::assertSame('main', $sent->getName());
        self::assertSame(['+15105550100'], iterator_to_array($sent->getNumbers(), false));
        self::assertSame('inbound-trunk', $sent->getMetadata());
        self::assertSame(['1.2.3.4/32'], iterator_to_array($sent->getAllowedAddresses(), false));
        self::assertSame(['+15105550199'], iterator_to_array($sent->getAllowedNumbers(), false));
        self::assertSame('sip-user', $sent->getAuthUsername());
        self::assertSame('sip-pass', $sent->getAuthPassword());
        self::assertSame('example.com', $sent->getAuthRealm());
        self::assertSame(['X-Lk' => 'yes'], iterator_to_array($sent->getHeaders()));
        self::assertSame(['X-Lk' => 'attr.lk'], iterator_to_array($sent->getHeadersToAttributes()));
        self::assertSame(['attr.out' => 'X-Out'], iterator_to_array($sent->getAttributesToHeaders()));
        self::assertSame(SIPHeaderOptions::SIP_X_HEADERS, $sent->getIncludeHeaders());
        self::assertTrue($sent->getKrispEnabled());
        self::assertSame(25, (int) $sent->getRingingTimeout()?->getSeconds());
        self::assertSame(600, (int) $sent->getMaxCallDuration()?->getSeconds());

        // Return value is the unwrapped response message.
        self::assertSame('ST_inbound', $trunk->getSipTrunkId());
        self::assertSame(1, $this->http->requestCount());
    }

    public function testCreateSipOutboundTrunk(): void
    {
        $this->http->pushResponse($this->protoResponse(
            (new SIPOutboundTrunkInfo())->setSipTrunkId('ST_outbound'),
        ));

        $trunk = $this->client->createSipOutboundTrunk(
            'carrier',
            'sip.carrier.example:5060',
            ['+15105550100', '+15105550101'],
            new CreateSipOutboundTrunkOptions(
                transport: SIPTransport::SIP_TRANSPORT_TCP,
                metadata: 'outbound-trunk',
                destinationCountry: 'US',
                authUsername: 'out-user',
                authPassword: 'out-pass',
                headers: ['X-Out' => '1'],
                headersToAttributes: ['X-Out' => 'attr.out'],
                attributesToHeaders: ['attr.in' => 'X-In'],
                includeHeaders: SIPHeaderOptions::SIP_ALL_HEADERS,
                fromHost: 'calls.example.com',
            ),
        );

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'CreateSIPOutboundTrunk');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);

        $sent = $this->decodeRequest(CreateSIPOutboundTrunkRequest::class)->getTrunk();
        self::assertInstanceOf(SIPOutboundTrunkInfo::class, $sent);
        self::assertSame('carrier', $sent->getName());
        self::assertSame('sip.carrier.example:5060', $sent->getAddress());
        self::assertSame(
            ['+15105550100', '+15105550101'],
            iterator_to_array($sent->getNumbers(), false),
        );
        self::assertSame('outbound-trunk', $sent->getMetadata());
        self::assertSame(SIPTransport::SIP_TRANSPORT_TCP, $sent->getTransport());
        self::assertSame('US', $sent->getDestinationCountry());
        self::assertSame('out-user', $sent->getAuthUsername());
        self::assertSame('out-pass', $sent->getAuthPassword());
        self::assertSame(['X-Out' => '1'], iterator_to_array($sent->getHeaders()));
        self::assertSame(['X-Out' => 'attr.out'], iterator_to_array($sent->getHeadersToAttributes()));
        self::assertSame(['attr.in' => 'X-In'], iterator_to_array($sent->getAttributesToHeaders()));
        self::assertSame(SIPHeaderOptions::SIP_ALL_HEADERS, $sent->getIncludeHeaders());
        self::assertSame('calls.example.com', $sent->getFromHost());

        self::assertSame('ST_outbound', $trunk->getSipTrunkId());
    }

    public function testCreateSipOutboundTrunkDefaultsToAutoTransport(): void
    {
        $this->http->pushResponse($this->protoResponse(new SIPOutboundTrunkInfo()));

        $this->client->createSipOutboundTrunk('carrier', 'sip.carrier.example', ['+15105550100']);

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'CreateSIPOutboundTrunk');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);

        $sent = $this->decodeRequest(CreateSIPOutboundTrunkRequest::class)->getTrunk();
        self::assertInstanceOf(SIPOutboundTrunkInfo::class, $sent);
        self::assertSame('carrier', $sent->getName());
        self::assertSame('sip.carrier.example', $sent->getAddress());
        self::assertSame(['+15105550100'], iterator_to_array($sent->getNumbers(), false));
        self::assertSame(SIPTransport::SIP_TRANSPORT_AUTO, $sent->getTransport());
        self::assertSame('', $sent->getMetadata());
    }

    public function testUpdateSipInboundTrunkSendsTheReplaceArm(): void
    {
        $this->http->pushResponse($this->protoResponse(
            (new SIPInboundTrunkInfo())->setSipTrunkId('ST_inbound')->setName('renamed'),
        ));

        $replacement = (new SIPInboundTrunkInfo())
            ->setName('renamed')
            ->setNumbers(['+15105550100'])
            ->setKrispEnabled(true);

        $trunk = $this->client->updateSipInboundTrunk('ST_inbound', $replacement);

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'UpdateSIPInboundTrunk');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);

        $sent = $this->decodeRequest(UpdateSIPInboundTrunkRequest::class);
        self::assertSame('ST_inbound', $sent->getSipTrunkId());
        self::assertSame('replace', $sent->getAction());
        self::assertNull($sent->getUpdate());

        $sentTrunk = $sent->getReplace();
        self::assertInstanceOf(SIPInboundTrunkInfo::class, $sentTrunk);
        self::assertSame('renamed', $sentTrunk->getName());
        self::assertSame(['+15105550100'], iterator_to_array($sentTrunk->getNumbers(), false));
        self::assertTrue($sentTrunk->getKrispEnabled());

        self::assertSame('renamed', $trunk->getName());
    }

    public function testUpdateSipInboundTrunkFieldsSendsTheUpdateArm(): void
    {
        $this->http->pushResponse($this->protoResponse(
            (new SIPInboundTrunkInfo())->setSipTrunkId('ST_inbound'),
        ));

        $this->client->updateSipInboundTrunkFields(
            'ST_inbound',
            new SipInboundTrunkUpdateOptions(
                numbers: (new ListUpdate())->setAdd(['+15105550111']),
                allowedAddresses: (new ListUpdate())->setSet(['10.0.0.0/8']),
                allowedNumbers: (new ListUpdate())->setClear(true),
                authUsername: 'new-user',
                authPassword: 'new-pass',
                authRealm: 'realm.example.com',
                name: 'renamed',
                metadata: 'updated',
            ),
        );

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'UpdateSIPInboundTrunk');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);

        $sent = $this->decodeRequest(UpdateSIPInboundTrunkRequest::class);
        self::assertSame('ST_inbound', $sent->getSipTrunkId());
        self::assertSame('update', $sent->getAction());
        self::assertNull($sent->getReplace());

        $update = $sent->getUpdate();
        self::assertNotNull($update);
        self::assertSame(['+15105550111'], iterator_to_array($update->getNumbers()->getAdd(), false));
        self::assertSame(['10.0.0.0/8'], iterator_to_array($update->getAllowedAddresses()->getSet(), false));
        self::assertTrue($update->getAllowedNumbers()->getClear());
        self::assertSame('new-user', $update->getAuthUsername());
        self::assertSame('new-pass', $update->getAuthPassword());
        self::assertSame('realm.example.com', $update->getAuthRealm());
        self::assertSame('renamed', $update->getName());
        self::assertSame('updated', $update->getMetadata());
    }

    public function testUpdateSipInboundTrunkFieldsOmitsUnsetOptionalScalars(): void
    {
        $this->http->pushResponse($this->protoResponse(new SIPInboundTrunkInfo()));

        $this->client->updateSipInboundTrunkFields(
            'ST_inbound',
            new SipInboundTrunkUpdateOptions(name: 'only-the-name'),
        );

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'UpdateSIPInboundTrunk');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);

        $sent = $this->decodeRequest(UpdateSIPInboundTrunkRequest::class);
        self::assertSame('ST_inbound', $sent->getSipTrunkId());
        self::assertSame('update', $sent->getAction());

        $update = $sent->getUpdate();
        self::assertNotNull($update);
        self::assertSame('only-the-name', $update->getName());

        // proto3 optional: an untouched field must stay absent, not be sent as ''.
        self::assertFalse($update->hasMetadata());
        self::assertFalse($update->hasAuthUsername());
        self::assertFalse($update->hasAuthPassword());
        self::assertFalse($update->hasAuthRealm());
        self::assertNull($update->getNumbers());
        self::assertNull($update->getAllowedAddresses());
        self::assertNull($update->getAllowedNumbers());
    }

    public function testUpdateSipOutboundTrunkSendsTheReplaceArm(): void
    {
        $this->http->pushResponse($this->protoResponse(
            (new SIPOutboundTrunkInfo())->setSipTrunkId('ST_outbound')->setAddress('new.example'),
        ));

        $replacement = (new SIPOutboundTrunkInfo())
            ->setName('carrier')
            ->setAddress('new.example')
            ->setTransport(SIPTransport::SIP_TRANSPORT_TLS);

        $trunk = $this->client->updateSipOutboundTrunk('ST_outbound', $replacement);

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'UpdateSIPOutboundTrunk');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);

        $sent = $this->decodeRequest(UpdateSIPOutboundTrunkRequest::class);
        self::assertSame('ST_outbound', $sent->getSipTrunkId());
        self::assertSame('replace', $sent->getAction());
        self::assertNull($sent->getUpdate());

        $sentTrunk = $sent->getReplace();
        self::assertInstanceOf(SIPOutboundTrunkInfo::class, $sentTrunk);
        self::assertSame('new.example', $sentTrunk->getAddress());
        self::assertSame(SIPTransport::SIP_TRANSPORT_TLS, $sentTrunk->getTransport());

        self::assertSame('new.example', $trunk->getAddress());
    }
}
