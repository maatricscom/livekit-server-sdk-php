<?php

declare(strict_types=1);

namespace LiveKit\Tests\Services;

use LiveKit\Options\CreateSipInboundTrunkOptions;
use LiveKit\Proto\CreateSIPInboundTrunkRequest;
use LiveKit\Proto\SIPHeaderOptions;
use LiveKit\Proto\SIPInboundTrunkInfo;
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
}
