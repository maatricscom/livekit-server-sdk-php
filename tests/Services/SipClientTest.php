<?php

declare(strict_types=1);

namespace LiveKit\Tests\Services;

use LiveKit\Options\CreateSipDispatchRuleOptions;
use LiveKit\Options\CreateSipInboundTrunkOptions;
use LiveKit\Options\CreateSipOutboundTrunkOptions;
use LiveKit\Options\ListSipDispatchRuleOptions;
use LiveKit\Options\ListSipTrunkOptions;
use LiveKit\Options\SipDispatchRuleUpdateOptions;
use LiveKit\Options\SipInboundTrunkUpdateOptions;
use LiveKit\Options\SipOutboundTrunkUpdateOptions;
use LiveKit\Proto\CreateSIPDispatchRuleRequest;
use LiveKit\Proto\CreateSIPInboundTrunkRequest;
use LiveKit\Proto\CreateSIPOutboundTrunkRequest;
use LiveKit\Proto\DeleteSIPTrunkRequest;
use LiveKit\Proto\GetSIPInboundTrunkRequest;
use LiveKit\Proto\GetSIPInboundTrunkResponse;
use LiveKit\Proto\GetSIPOutboundTrunkRequest;
use LiveKit\Proto\GetSIPOutboundTrunkResponse;
use LiveKit\Proto\ListSIPDispatchRuleRequest;
use LiveKit\Proto\ListSIPDispatchRuleResponse;
use LiveKit\Proto\ListSIPInboundTrunkRequest;
use LiveKit\Proto\ListSIPInboundTrunkResponse;
use LiveKit\Proto\ListSIPOutboundTrunkRequest;
use LiveKit\Proto\ListSIPOutboundTrunkResponse;
use LiveKit\Proto\ListSIPTrunkRequest;
use LiveKit\Proto\ListSIPTrunkResponse;
use LiveKit\Proto\ListUpdate;
use LiveKit\Proto\Pagination;
use LiveKit\Proto\RoomConfiguration;
use LiveKit\Proto\SIPDispatchRule;
use LiveKit\Proto\SIPDispatchRuleDirect;
use LiveKit\Proto\SIPDispatchRuleIndividual;
use LiveKit\Proto\SIPDispatchRuleInfo;
use LiveKit\Proto\SIPHeaderOptions;
use LiveKit\Proto\SIPInboundTrunkInfo;
use LiveKit\Proto\SIPOutboundTrunkInfo;
use LiveKit\Proto\SIPTransport;
use LiveKit\Proto\SIPTrunkInfo;
use LiveKit\Proto\UpdateSIPDispatchRuleRequest;
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
        self::assertNotNull($update->getNumbers());
        self::assertNotNull($update->getAllowedAddresses());
        self::assertNotNull($update->getAllowedNumbers());
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

    public function testUpdateSipOutboundTrunkFieldsSendsTheUpdateArm(): void
    {
        $this->http->pushResponse($this->protoResponse(
            (new SIPOutboundTrunkInfo())->setSipTrunkId('ST_outbound'),
        ));

        $this->client->updateSipOutboundTrunkFields(
            'ST_outbound',
            new SipOutboundTrunkUpdateOptions(
                address: 'sip2.carrier.example',
                transport: SIPTransport::SIP_TRANSPORT_UDP,
                destinationCountry: 'DE',
                numbers: (new ListUpdate())->setRemove(['+15105550101']),
                authUsername: 'out-user-2',
                authPassword: 'out-pass-2',
                name: 'carrier-2',
                metadata: 'updated',
                fromHost: 'calls2.example.com',
            ),
        );

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'UpdateSIPOutboundTrunk');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);

        $sent = $this->decodeRequest(UpdateSIPOutboundTrunkRequest::class);
        self::assertSame('ST_outbound', $sent->getSipTrunkId());
        self::assertSame('update', $sent->getAction());
        self::assertNull($sent->getReplace());

        $update = $sent->getUpdate();
        self::assertNotNull($update);
        self::assertSame('sip2.carrier.example', $update->getAddress());
        self::assertSame(SIPTransport::SIP_TRANSPORT_UDP, $update->getTransport());
        self::assertSame('DE', $update->getDestinationCountry());
        self::assertNotNull($update->getNumbers());
        self::assertSame(['+15105550101'], iterator_to_array($update->getNumbers()->getRemove(), false));
        self::assertSame('out-user-2', $update->getAuthUsername());
        self::assertSame('out-pass-2', $update->getAuthPassword());
        self::assertSame('carrier-2', $update->getName());
        self::assertSame('updated', $update->getMetadata());
        self::assertSame('calls2.example.com', $update->getFromHost());
    }

    public function testUpdateSipOutboundTrunkFieldsOmitsUnsetOptionalScalars(): void
    {
        $this->http->pushResponse($this->protoResponse(new SIPOutboundTrunkInfo()));

        $this->client->updateSipOutboundTrunkFields(
            'ST_outbound',
            new SipOutboundTrunkUpdateOptions(address: 'only.the.address'),
        );

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'UpdateSIPOutboundTrunk');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);

        $sent = $this->decodeRequest(UpdateSIPOutboundTrunkRequest::class);
        self::assertSame('ST_outbound', $sent->getSipTrunkId());
        self::assertSame('update', $sent->getAction());

        $update = $sent->getUpdate();
        self::assertNotNull($update);
        self::assertSame('only.the.address', $update->getAddress());
        self::assertFalse($update->hasTransport());
        self::assertFalse($update->hasName());
        self::assertFalse($update->hasMetadata());
        self::assertFalse($update->hasFromHost());
        self::assertNull($update->getNumbers());
    }

    public function testGetSipInboundTrunkUnwrapsTheResponse(): void
    {
        $this->http->pushResponse($this->protoResponse(
            (new GetSIPInboundTrunkResponse())->setTrunk(
                (new SIPInboundTrunkInfo())->setSipTrunkId('ST_inbound')->setName('main'),
            ),
        ));

        $trunk = $this->client->getSipInboundTrunk('ST_inbound');

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'GetSIPInboundTrunk');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);

        $sent = $this->decodeRequest(GetSIPInboundTrunkRequest::class);
        self::assertSame('ST_inbound', $sent->getSipTrunkId());

        self::assertInstanceOf(SIPInboundTrunkInfo::class, $trunk);
        self::assertSame('main', $trunk->getName());
    }

    public function testGetSipInboundTrunkReturnsNullWhenTheTrunkIsAbsent(): void
    {
        $this->http->pushResponse($this->protoResponse(new GetSIPInboundTrunkResponse()));

        self::assertNull($this->client->getSipInboundTrunk('ST_missing'));

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'GetSIPInboundTrunk');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);
        self::assertSame(
            'ST_missing',
            $this->decodeRequest(GetSIPInboundTrunkRequest::class)->getSipTrunkId(),
        );
    }

    public function testGetSipOutboundTrunkUnwrapsTheResponse(): void
    {
        $this->http->pushResponse($this->protoResponse(
            (new GetSIPOutboundTrunkResponse())->setTrunk(
                (new SIPOutboundTrunkInfo())
                    ->setSipTrunkId('ST_outbound')
                    ->setAddress('sip.carrier.example'),
            ),
        ));

        $trunk = $this->client->getSipOutboundTrunk('ST_outbound');

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'GetSIPOutboundTrunk');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);

        $sent = $this->decodeRequest(GetSIPOutboundTrunkRequest::class);
        self::assertSame('ST_outbound', $sent->getSipTrunkId());

        self::assertInstanceOf(SIPOutboundTrunkInfo::class, $trunk);
        self::assertSame('sip.carrier.example', $trunk->getAddress());
    }

    public function testGetSipOutboundTrunkReturnsNullWhenTheTrunkIsAbsent(): void
    {
        $this->http->pushResponse($this->protoResponse(new GetSIPOutboundTrunkResponse()));

        self::assertNull($this->client->getSipOutboundTrunk('ST_missing'));

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'GetSIPOutboundTrunk');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);
        self::assertSame(
            'ST_missing',
            $this->decodeRequest(GetSIPOutboundTrunkRequest::class)->getSipTrunkId(),
        );
    }

    public function testListSipInboundTrunkUnwrapsToAnArray(): void
    {
        $this->http->pushResponse($this->protoResponse(
            (new ListSIPInboundTrunkResponse())->setItems([
                (new SIPInboundTrunkInfo())->setSipTrunkId('ST_a'),
                (new SIPInboundTrunkInfo())->setSipTrunkId('ST_b'),
            ]),
        ));

        $trunks = $this->client->listSipInboundTrunk(
            new ListSipTrunkOptions(
                page: (new Pagination())->setAfterId('ST_0')->setLimit(50),
                trunkIds: ['ST_a', 'ST_b'],
                numbers: ['+15105550100'],
            ),
        );

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'ListSIPInboundTrunk');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);

        $sent = $this->decodeRequest(ListSIPInboundTrunkRequest::class);
        self::assertSame(['ST_a', 'ST_b'], iterator_to_array($sent->getTrunkIds(), false));
        self::assertSame(['+15105550100'], iterator_to_array($sent->getNumbers(), false));
        self::assertSame('ST_0', $sent->getPage()?->getAfterId());
        self::assertSame(50, $sent->getPage()?->getLimit());

        // The list RPC unwraps: an array of messages, never the response wrapper.
        self::assertCount(2, $trunks);
        self::assertSame('ST_a', $trunks[0]->getSipTrunkId());
        self::assertSame('ST_b', $trunks[1]->getSipTrunkId());
    }

    public function testListSipInboundTrunkWithNoFiltersSendsAnEmptyRequest(): void
    {
        $this->http->pushResponse($this->protoResponse(new ListSIPInboundTrunkResponse()));

        self::assertSame([], $this->client->listSipInboundTrunk());

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'ListSIPInboundTrunk');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);

        // No filters set: an all-defaults proto message serialises to zero bytes.
        self::assertSame('', $this->http->lastBody());

        $sent = $this->decodeRequest(ListSIPInboundTrunkRequest::class);
        self::assertNull($sent->getPage());
        self::assertCount(0, $sent->getTrunkIds());
        self::assertCount(0, $sent->getNumbers());
    }

    public function testListSipOutboundTrunkUnwrapsToAnArray(): void
    {
        $this->http->pushResponse($this->protoResponse(
            (new ListSIPOutboundTrunkResponse())->setItems([
                (new SIPOutboundTrunkInfo())->setSipTrunkId('ST_c'),
            ]),
        ));

        $trunks = $this->client->listSipOutboundTrunk(
            new ListSipTrunkOptions(trunkIds: ['ST_c'], numbers: ['+15105550101']),
        );

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'ListSIPOutboundTrunk');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);

        $sent = $this->decodeRequest(ListSIPOutboundTrunkRequest::class);
        self::assertSame(['ST_c'], iterator_to_array($sent->getTrunkIds(), false));
        self::assertSame(['+15105550101'], iterator_to_array($sent->getNumbers(), false));
        self::assertNull($sent->getPage());

        self::assertCount(1, $trunks);
        self::assertSame('ST_c', $trunks[0]->getSipTrunkId());
    }

    public function testListSipTrunkUnwrapsToAnArray(): void
    {
        $this->http->pushResponse($this->protoResponse(
            (new ListSIPTrunkResponse())->setItems([
                (new SIPTrunkInfo())->setSipTrunkId('ST_legacy'),
            ]),
        ));

        $trunks = $this->client->listSipTrunk();

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'ListSIPTrunk');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);

        // Node sends an empty ListSIPTrunkRequest; an empty proto message is zero bytes.
        self::assertSame('', $this->http->lastBody());
        self::assertInstanceOf(
            ListSIPTrunkRequest::class,
            $this->decodeRequest(ListSIPTrunkRequest::class),
        );

        self::assertCount(1, $trunks);
        self::assertSame('ST_legacy', $trunks[0]->getSipTrunkId());
    }

    public function testDeleteSipTrunk(): void
    {
        $this->http->pushResponse($this->protoResponse(
            (new SIPTrunkInfo())->setSipTrunkId('ST_inbound'),
        ));

        $deleted = $this->client->deleteSipTrunk('ST_inbound');

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'DeleteSIPTrunk');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);

        $sent = $this->decodeRequest(DeleteSIPTrunkRequest::class);
        self::assertSame('ST_inbound', $sent->getSipTrunkId());

        // DeleteSIPTrunk returns the legacy SIPTrunkInfo shape for both trunk kinds.
        self::assertSame('ST_inbound', $deleted->getSipTrunkId());
    }

    public function testCreateSipDispatchRuleDirect(): void
    {
        $this->http->pushResponse($this->protoResponse(
            (new SIPDispatchRuleInfo())->setSipDispatchRuleId('SDR_direct'),
        ));

        $rule = (new SIPDispatchRule())->setDispatchRuleDirect(
            (new SIPDispatchRuleDirect())->setRoomName('support')->setPin('1234'),
        );

        $info = $this->client->createSipDispatchRule(
            $rule,
            new CreateSipDispatchRuleOptions(
                name: 'support-line',
                metadata: 'rule-metadata',
                trunkIds: ['ST_inbound'],
                hidePhoneNumber: true,
                inboundNumbers: ['+15105550100'],
                attributes: ['tier' => 'gold'],
                roomPreset: 'preset-a',
                roomConfig: (new RoomConfiguration())->setName('support'),
            ),
        );

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'CreateSIPDispatchRule');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);

        $sent = $this->decodeRequest(CreateSIPDispatchRuleRequest::class);
        self::assertSame('dispatch_rule_direct', $sent->getRule()?->getRule());
        $directRule = $sent->getRule()?->getDispatchRuleDirect();
        self::assertInstanceOf(SIPDispatchRuleDirect::class, $directRule);
        self::assertSame('support', $directRule->getRoomName());
        self::assertSame('1234', $directRule->getPin());
        self::assertSame(['ST_inbound'], iterator_to_array($sent->getTrunkIds(), false));
        self::assertTrue($sent->getHidePhoneNumber());
        self::assertSame(['+15105550100'], iterator_to_array($sent->getInboundNumbers(), false));
        self::assertSame('support-line', $sent->getName());
        self::assertSame('rule-metadata', $sent->getMetadata());
        self::assertSame(['tier' => 'gold'], iterator_to_array($sent->getAttributes()));
        self::assertSame('preset-a', $sent->getRoomPreset());
        self::assertSame('support', $sent->getRoomConfig()?->getName());

        self::assertSame('SDR_direct', $info->getSipDispatchRuleId());
    }

    public function testCreateSipDispatchRuleIndividualWithoutOptions(): void
    {
        $this->http->pushResponse($this->protoResponse(new SIPDispatchRuleInfo()));

        $rule = (new SIPDispatchRule())->setDispatchRuleIndividual(
            (new SIPDispatchRuleIndividual())->setRoomPrefix('call-'),
        );

        $this->client->createSipDispatchRule($rule);

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'CreateSIPDispatchRule');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);

        $sent = $this->decodeRequest(CreateSIPDispatchRuleRequest::class);
        self::assertSame('dispatch_rule_individual', $sent->getRule()?->getRule());
        self::assertSame('call-', $sent->getRule()?->getDispatchRuleIndividual()?->getRoomPrefix());
        self::assertSame('', $sent->getName());
        self::assertCount(0, $sent->getTrunkIds());
    }

    public function testUpdateSipDispatchRuleSendsTheReplaceArm(): void
    {
        $this->http->pushResponse($this->protoResponse(
            (new SIPDispatchRuleInfo())->setSipDispatchRuleId('SDR_direct')->setName('renamed'),
        ));

        $replacement = (new SIPDispatchRuleInfo())
            ->setName('renamed')
            ->setTrunkIds(['ST_inbound'])
            ->setRule(
                (new SIPDispatchRule())->setDispatchRuleDirect(
                    (new SIPDispatchRuleDirect())->setRoomName('support'),
                ),
            );

        $info = $this->client->updateSipDispatchRule('SDR_direct', $replacement);

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'UpdateSIPDispatchRule');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);

        $sent = $this->decodeRequest(UpdateSIPDispatchRuleRequest::class);
        self::assertSame('SDR_direct', $sent->getSipDispatchRuleId());
        self::assertSame('replace', $sent->getAction());
        self::assertNull($sent->getUpdate());

        $sentRule = $sent->getReplace();
        self::assertInstanceOf(SIPDispatchRuleInfo::class, $sentRule);
        self::assertSame('renamed', $sentRule->getName());
        self::assertSame(['ST_inbound'], iterator_to_array($sentRule->getTrunkIds(), false));

        self::assertSame('renamed', $info->getName());
    }

    public function testUpdateSipDispatchRuleFieldsSendsTheUpdateArm(): void
    {
        $this->http->pushResponse($this->protoResponse(
            (new SIPDispatchRuleInfo())->setSipDispatchRuleId('SDR_direct'),
        ));

        $this->client->updateSipDispatchRuleFields(
            'SDR_direct',
            new SipDispatchRuleUpdateOptions(
                trunkIds: (new ListUpdate())->setAdd(['ST_extra']),
                rule: (new SIPDispatchRule())->setDispatchRuleIndividual(
                    (new SIPDispatchRuleIndividual())->setRoomPrefix('call-'),
                ),
                name: 'renamed',
                metadata: 'updated',
                attributes: ['tier' => 'silver'],
            ),
        );

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'UpdateSIPDispatchRule');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);

        $sent = $this->decodeRequest(UpdateSIPDispatchRuleRequest::class);
        self::assertSame('SDR_direct', $sent->getSipDispatchRuleId());
        self::assertSame('update', $sent->getAction());
        self::assertNull($sent->getReplace());

        $update = $sent->getUpdate();
        self::assertNotNull($update);
        self::assertNotNull($update->getTrunkIds());
        self::assertSame(['ST_extra'], iterator_to_array($update->getTrunkIds()->getAdd(), false));
        self::assertSame('dispatch_rule_individual', $update->getRule()?->getRule());
        self::assertSame('renamed', $update->getName());
        self::assertSame('updated', $update->getMetadata());
        self::assertSame(['tier' => 'silver'], iterator_to_array($update->getAttributes()));
    }

    public function testUpdateSipDispatchRuleFieldsOmitsUnsetOptionalScalars(): void
    {
        $this->http->pushResponse($this->protoResponse(new SIPDispatchRuleInfo()));

        $this->client->updateSipDispatchRuleFields(
            'SDR_direct',
            new SipDispatchRuleUpdateOptions(name: 'only-the-name'),
        );

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'UpdateSIPDispatchRule');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);

        $sent = $this->decodeRequest(UpdateSIPDispatchRuleRequest::class);
        self::assertSame('SDR_direct', $sent->getSipDispatchRuleId());
        self::assertSame('update', $sent->getAction());

        $update = $sent->getUpdate();
        self::assertNotNull($update);
        self::assertSame('only-the-name', $update->getName());
        self::assertFalse($update->hasMetadata());
        self::assertNull($update->getTrunkIds());
        self::assertNull($update->getRule());
        self::assertCount(0, $update->getAttributes());
    }

    public function testListSipDispatchRuleUnwrapsToAnArray(): void
    {
        $this->http->pushResponse($this->protoResponse(
            (new ListSIPDispatchRuleResponse())->setItems([
                (new SIPDispatchRuleInfo())->setSipDispatchRuleId('SDR_a'),
                (new SIPDispatchRuleInfo())->setSipDispatchRuleId('SDR_b'),
            ]),
        ));

        $rules = $this->client->listSipDispatchRule(
            new ListSipDispatchRuleOptions(
                page: (new Pagination())->setLimit(10),
                dispatchRuleIds: ['SDR_a', 'SDR_b'],
                trunkIds: ['ST_inbound'],
            ),
        );

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'ListSIPDispatchRule');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);

        $sent = $this->decodeRequest(ListSIPDispatchRuleRequest::class);
        self::assertSame(['SDR_a', 'SDR_b'], iterator_to_array($sent->getDispatchRuleIds(), false));
        self::assertSame(['ST_inbound'], iterator_to_array($sent->getTrunkIds(), false));
        self::assertSame(10, $sent->getPage()?->getLimit());

        self::assertCount(2, $rules);
        self::assertSame('SDR_a', $rules[0]->getSipDispatchRuleId());
        self::assertSame('SDR_b', $rules[1]->getSipDispatchRuleId());
    }

    public function testListSipDispatchRuleWithNoFiltersSendsAnEmptyRequest(): void
    {
        $this->http->pushResponse($this->protoResponse(new ListSIPDispatchRuleResponse()));

        self::assertSame([], $this->client->listSipDispatchRule());

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'SIP', 'ListSIPDispatchRule');
        $this->assertSipGrant(['admin' => true], $request);
        $this->assertVideoGrant([], $request);

        self::assertSame('', $this->http->lastBody());

        $sent = $this->decodeRequest(ListSIPDispatchRuleRequest::class);
        self::assertNull($sent->getPage());
        self::assertCount(0, $sent->getDispatchRuleIds());
        self::assertCount(0, $sent->getTrunkIds());
    }
}
