<?php

declare(strict_types=1);

namespace LiveKit\Tests\Http;

use LiveKit\Enums\WireFormat;
use LiveKit\Exceptions\ConfigurationException;
use LiveKit\Exceptions\LiveKitException;
use LiveKit\Exceptions\SipCallError;
use LiveKit\Exceptions\TwirpErrorCode;
use LiveKit\Exceptions\TwirpException;
use LiveKit\Http\Failover;
use LiveKit\Http\RegionCache;
use LiveKit\Http\TwirpClient;
use LiveKit\Options\ClientOptions;
use LiveKit\Proto\CreateRoomRequest;
use LiveKit\Proto\Room;
use LiveKit\ProtocolVersion;
use LiveKit\Tests\Support\MockHttpClient;
use LiveKit\Tests\Support\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientExceptionInterface;

final class TwirpClientTest extends TestCase
{
    private function transport(
        MockHttpClient $http,
        ?ClientOptions $options = null,
        string $host = 'https://example.livekit.cloud',
        ?RegionCache $regionCache = null,
    ): TwirpClient {
        $factory = new Psr17Factory();

        // A cache of its own per transport unless the test says otherwise: the
        // shared one is process-wide, and a region list left behind by one test
        // would change what the next one discovers.
        return new TwirpClient(
            $host,
            $options ?? new ClientOptions(),
            $http,
            $factory,
            $factory,
            $regionCache ?? new RegionCache(),
        );
    }

    private function roomResponse(string $name): Response
    {
        $room = new Room();
        $room->setName($name);

        return new Response(200, ['Content-Type' => 'application/protobuf'], $room->serializeToString());
    }

    public function test_posts_to_the_twirp_path_for_the_service_and_method(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->roomResponse('my-room'));

        $request = new CreateRoomRequest();
        $request->setName('my-room');

        $this->transport($http)->request('RoomService', 'CreateRoom', $request, Room::class, 'jwt-token');

        $sent = $http->lastRequest();

        self::assertSame('POST', $sent->getMethod());
        self::assertSame(
            'https://example.livekit.cloud/twirp/livekit.RoomService/CreateRoom',
            (string) $sent->getUri()
        );
    }

    public function test_sends_binary_protobuf_by_default(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->roomResponse('my-room'));

        $request = new CreateRoomRequest();
        $request->setName('my-room');
        $request->setEmptyTimeout(300);

        $this->transport($http)->request('RoomService', 'CreateRoom', $request, Room::class, 'jwt-token');

        $sent = $http->lastRequest();

        self::assertSame('application/protobuf', $sent->getHeaderLine('Content-Type'));

        $decoded = new CreateRoomRequest();
        $decoded->mergeFromString((string) $sent->getBody());

        self::assertSame('my-room', $decoded->getName());
        self::assertSame(300, $decoded->getEmptyTimeout());
    }

    public function test_sends_json_when_the_wire_format_is_json(): void
    {
        $http = new MockHttpClient();

        $room = new Room();
        $room->setName('my-room');
        $http->pushResponse(new Response(200, ['Content-Type' => 'application/json'], $room->serializeToJsonString()));

        $request = new CreateRoomRequest();
        $request->setName('my-room');

        $this->transport($http, new ClientOptions(wireFormat: WireFormat::Json))
            ->request('RoomService', 'CreateRoom', $request, Room::class, 'jwt-token');

        $sent = $http->lastRequest();

        self::assertSame('application/json', $sent->getHeaderLine('Content-Type'));
        self::assertJsonStringEqualsJsonString('{"name":"my-room"}', (string) $sent->getBody());
    }

    /**
     * The one-argument form of mergeFromJsonString throws GPBDecodeException on
     * any unrecognized key, which would break the SDK the moment LiveKit adds a
     * field. The transport must always pass ignore_unknown = true.
     */
    public function test_json_mode_ignores_unknown_response_fields(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse(new Response(
            200,
            ['Content-Type' => 'application/json'],
            '{"name":"my-room","brandNewFieldFromANewerServer":true}'
        ));

        $room = $this->transport($http, new ClientOptions(wireFormat: WireFormat::Json))
            ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt-token');

        self::assertInstanceOf(Room::class, $room);
        self::assertSame('my-room', $room->getName());
    }

    /**
     * mergeFromJsonString('', true) throws GPBDecodeException on empty input.
     * Binary mode's mergeFromString('') does not — it yields an all-defaults
     * message — so a 204, or a proxy that strips the body, must behave the
     * same way in JSON mode rather than leaking a non-SDK exception.
     */
    public function test_json_mode_treats_an_empty_2xx_body_as_a_default_message(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse(new Response(200, ['Content-Type' => 'application/json'], ''));

        $room = $this->transport($http, new ClientOptions(wireFormat: WireFormat::Json))
            ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt-token');

        self::assertInstanceOf(Room::class, $room);
        self::assertSame('', $room->getName());
    }

    /**
     * A malformed JSON body must still surface as TwirpException — the type the
     * method's contract promises — not as a raw GPBDecodeException that a
     * caller catching LiveKitException would miss entirely.
     */
    public function test_json_mode_wraps_malformed_json_in_a_twirp_exception(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse(new Response(200, ['Content-Type' => 'application/json'], '{not valid json'));

        try {
            $this->transport($http, new ClientOptions(wireFormat: WireFormat::Json))
                ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt-token');
            self::fail('Expected a TwirpException');
        } catch (TwirpException $e) {
            self::assertNotInstanceOf(SipCallError::class, $e);
        }
    }

    public function test_sends_the_authorization_user_agent_and_request_id_headers(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->roomResponse('my-room'));

        $this->transport($http)->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt-token');

        $sent = $http->lastRequest();

        self::assertSame('Bearer jwt-token', $sent->getHeaderLine('Authorization'));
        self::assertStringStartsWith('livekit-server-sdk-php/', $sent->getHeaderLine('User-Agent'));
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $sent->getHeaderLine('X-Livekit-Request-Id')
        );
    }

    /**
     * The protocol revision matters as much as the SDK version: it is what says
     * whether a field a caller expects is missing because of a bug or because the
     * server is newer than the tree this package was generated from.
     */
    public function test_user_agent_carries_both_the_sdk_and_protocol_versions(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->roomResponse('my-room'));

        $this->transport($http)->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');

        self::assertSame(
            sprintf('livekit-server-sdk-php/%s (protocol %s)', TwirpClient::VERSION, ProtocolVersion::TAG),
            $http->lastRequest()->getHeaderLine('User-Agent')
        );

        // Pin the shape rather than only the values, so a future edit cannot quietly
        // drop the protocol segment while still matching the prefix assertion above.
        self::assertMatchesRegularExpression(
            '/^livekit-server-sdk-php\/\S+ \(protocol v\d+\.\d+\.\d+\)$/',
            $http->lastRequest()->getHeaderLine('User-Agent')
        );
    }

    public function test_sends_the_twirp_timeout_header_from_client_options(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->roomResponse('my-room'));

        $this->transport($http, new ClientOptions(requestTimeout: 25))
            ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt-token');

        self::assertSame('25000', $http->lastRequest()->getHeaderLine('X-Twirp-Timeout-Ms'));
    }

    public function test_a_per_call_timeout_overrides_the_client_default(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->roomResponse('my-room'));

        $this->transport($http)->request(
            'SIP',
            'CreateSIPParticipant',
            new CreateRoomRequest(),
            Room::class,
            'jwt-token',
            timeoutSeconds: 32,
        );

        self::assertSame('32000', $http->lastRequest()->getHeaderLine('X-Twirp-Timeout-Ms'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function hostProvider(): array
    {
        return [
            'wss becomes https' => ['wss://x.livekit.cloud', 'https://x.livekit.cloud/twirp/livekit.RoomService/CreateRoom'],
            'ws becomes http' => ['ws://localhost:7880', 'http://localhost:7880/twirp/livekit.RoomService/CreateRoom'],
            'https is untouched' => ['https://x.livekit.cloud', 'https://x.livekit.cloud/twirp/livekit.RoomService/CreateRoom'],
            'trailing slash does not double up' => ['https://x.livekit.cloud/', 'https://x.livekit.cloud/twirp/livekit.RoomService/CreateRoom'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostProvider')]
    public function test_normalizes_the_host(string $host, string $expectedUri): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->roomResponse('my-room'));

        $this->transport($http, null, $host)
            ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt-token');

        self::assertSame($expectedUri, (string) $http->lastRequest()->getUri());
    }

    public function test_throws_a_twirp_exception_on_an_error_response(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse(new Response(
            404,
            ['Content-Type' => 'application/json'],
            '{"code":"not_found","msg":"room does not exist"}'
        ));

        try {
            $this->transport($http)->request('RoomService', 'DeleteRoom', new CreateRoomRequest(), Room::class, 'jwt');
            self::fail('Expected a TwirpException');
        } catch (TwirpException $e) {
            self::assertSame('not_found', $e->getTwirpCode());
            self::assertSame(404, $e->getHttpStatus());
            self::assertSame('room does not exist', $e->getMessage());
        }
    }

    /**
     * The SIP-aware subclass is chosen from the payload, not requested by the caller,
     * so no service client can ask for the wrong exception class.
     */
    public function test_upgrades_to_a_sip_call_error_when_the_meta_carries_a_sip_status(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse(new Response(
            500,
            ['Content-Type' => 'application/json'],
            '{"code":"internal","msg":"call failed","meta":{"sip_status_code":"486","sip_status":"Busy Here"}}'
        ));

        try {
            $this->transport($http)->request(
                'SIP',
                'CreateSIPParticipant',
                new CreateRoomRequest(),
                Room::class,
                'jwt',
            );
            self::fail('Expected a SipCallError');
        } catch (SipCallError $e) {
            self::assertSame('internal', $e->getTwirpCode());
            self::assertSame(486, $e->getSipStatusCode());
            self::assertSame('Busy Here', $e->getSipStatus());
        }
    }

    public function test_an_error_without_sip_meta_stays_a_plain_twirp_exception(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse(new Response(
            404,
            ['Content-Type' => 'application/json'],
            '{"code":"not_found","msg":"trunk does not exist"}'
        ));

        try {
            $this->transport($http)->request('SIP', 'DeleteSIPTrunk', new CreateRoomRequest(), Room::class, 'jwt');
            self::fail('Expected a TwirpException');
        } catch (TwirpException $e) {
            self::assertNotInstanceOf(SipCallError::class, $e);
            self::assertSame('not_found', $e->getTwirpCode());
        }
    }

    /**
     * This exercises the ordinary $jwt parameter, not ClientOptions::$token —
     * wiring a pre-signed token in place of minting one per call is a
     * service-client-layer concern for a later task; there is nothing to
     * verify about it here.
     */
    public function test_sends_the_supplied_jwt_as_a_bearer_token(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->roomResponse('my-room'));

        $this->transport($http)->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'pre-signed');

        self::assertSame('Bearer pre-signed', $http->lastRequest()->getHeaderLine('Authorization'));
    }

    public function test_wraps_a_transport_failure_in_a_twirp_exception(): void
    {
        $http = new MockHttpClient();
        $http->pushException(new class ('connection refused') extends \RuntimeException implements ClientExceptionInterface {});

        try {
            $this->transport($http)->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');
            self::fail('Expected a TwirpException');
        } catch (TwirpException $e) {
            self::assertSame('unavailable', $e->getTwirpCode());
            self::assertSame(0, $e->getHttpStatus());
            self::assertStringContainsString('connection refused', $e->getMessage());
            self::assertInstanceOf(ClientExceptionInterface::class, $e->getPrevious());
        }
    }

    // ---------------------------------------------------------------------
    // Region failover
    //
    // Failover replays a request against another LiveKit Cloud region. Two
    // properties matter more than the retrying itself: that the replay only ever
    // reaches a host under a domain LiveKit controls, since it carries the
    // caller's bearer token, and that it never happens for a request whose answer
    // would not change -- above all a SIP dial, where a retry rings a real phone.
    // ---------------------------------------------------------------------

    private function regionsResponse(string ...$urls): Response
    {
        $regions = [];

        foreach ($urls as $i => $url) {
            $regions[] = ['region' => 'region-' . $i, 'url' => $url];
        }

        return new Response(
            200,
            ['Content-Type' => 'application/json', 'Cache-Control' => 'max-age=60'],
            json_encode(['regions' => $regions], JSON_THROW_ON_ERROR)
        );
    }

    private function twirpErrorResponse(int $status, string $code, string $message = 'boom'): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode(['code' => $code, 'msg' => $message], JSON_THROW_ON_ERROR)
        );
    }

    private function noBackoff(): ClientOptions
    {
        return new ClientOptions(failoverBackoffMs: 0);
    }

    public function test_a_5xx_on_a_cloud_host_is_replayed_against_the_next_region(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->twirpErrorResponse(503, 'unavailable'));
        $http->pushResponse($this->regionsResponse('https://example.livekit.cloud', 'https://fallback.livekit.cloud'));
        $http->pushResponse($this->roomResponse('my-room'));

        $room = $this->transport($http, $this->noBackoff())
            ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');

        self::assertSame('my-room', $room->getName());

        $uris = array_map(static fn ($r): string => (string) $r->getUri(), $http->requests());

        self::assertSame([
            'https://example.livekit.cloud/twirp/livekit.RoomService/CreateRoom',
            'https://example.livekit.cloud/settings/regions',
            'https://fallback.livekit.cloud/twirp/livekit.RoomService/CreateRoom',
        ], $uris, 'The primary is skipped in the region list and the next one is used.');
    }

    public function test_a_replay_reuses_the_request_id_so_the_server_can_deduplicate(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->twirpErrorResponse(503, 'unavailable'));
        $http->pushResponse($this->regionsResponse('https://fallback.livekit.cloud'));
        $http->pushResponse($this->roomResponse('my-room'));

        $this->transport($http, $this->noBackoff())
            ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');

        $requests = $http->requests();
        $first = $requests[0]->getHeaderLine(TwirpClient::REQUEST_ID_HEADER);
        $replay = $requests[2]->getHeaderLine(TwirpClient::REQUEST_ID_HEADER);

        self::assertNotSame('', $first);
        self::assertSame($first, $replay, 'A retry is the same request, so it keeps its id.');
    }

    public function test_the_replayed_request_carries_the_same_body_and_credentials(): void
    {
        $request = new CreateRoomRequest();
        $request->setName('my-room');

        $http = new MockHttpClient();
        $http->pushResponse($this->twirpErrorResponse(503, 'unavailable'));
        $http->pushResponse($this->regionsResponse('https://fallback.livekit.cloud'));
        $http->pushResponse($this->roomResponse('my-room'));

        $this->transport($http, $this->noBackoff())
            ->request('RoomService', 'CreateRoom', $request, Room::class, 'jwt');

        $requests = $http->requests();

        self::assertSame((string) $requests[0]->getBody(), (string) $requests[2]->getBody());
        self::assertSame('Bearer jwt', $requests[2]->getHeaderLine('Authorization'));
    }

    public function test_a_4xx_is_not_replayed(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->twirpErrorResponse(404, 'not_found'));

        try {
            $this->transport($http, $this->noBackoff())
                ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');
            self::fail('Expected a TwirpException');
        } catch (TwirpException $e) {
            self::assertSame('not_found', $e->getTwirpCode());
        }

        self::assertSame(1, $http->requestCount(), 'A 4xx is the request\'s fault; another region says the same.');
    }

    public function test_a_sip_call_error_is_not_replayed_even_though_it_arrives_as_a_5xx(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse(new Response(500, ['Content-Type' => 'application/json'], json_encode([
            'code' => 'internal',
            'msg' => 'sip: call failed',
            'meta' => ['sip_status_code' => '486', 'sip_status' => 'Busy Here'],
        ], JSON_THROW_ON_ERROR)));

        try {
            $this->transport($http, $this->noBackoff())
                ->request('SIP', 'CreateSIPParticipant', new CreateRoomRequest(), Room::class, 'jwt');
            self::fail('Expected a SipCallError');
        } catch (SipCallError $e) {
            self::assertSame(486, $e->getSipStatusCode());
        }

        self::assertSame(
            1,
            $http->requestCount(),
            'A busy callee is a definitive answer. Replaying it would dial the number again.'
        );
    }

    public function test_failover_does_not_engage_for_a_host_outside_livekit_cloud(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->twirpErrorResponse(503, 'unavailable'));

        try {
            $this->transport($http, $this->noBackoff(), 'https://livekit.example.com')
                ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');
            self::fail('Expected a TwirpException');
        } catch (TwirpException) {
        }

        self::assertSame(1, $http->requestCount(), 'Self-hosted deployments have no region list to discover.');
    }

    public function test_a_lookalike_domain_is_not_treated_as_livekit_cloud(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->twirpErrorResponse(503, 'unavailable'));

        try {
            // Ends with the string "livekit.cloud" but is a different registrable
            // domain. Matching it would hand the caller's token to its owner.
            $this->transport($http, $this->noBackoff(), 'https://evil-livekit.cloud')
                ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');
            self::fail('Expected a TwirpException');
        } catch (TwirpException) {
        }

        self::assertSame(1, $http->requestCount());
    }

    public function test_failover_can_be_turned_off(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->twirpErrorResponse(503, 'unavailable'));

        try {
            $this->transport($http, new ClientOptions(failover: false, failoverBackoffMs: 0))
                ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');
            self::fail('Expected a TwirpException');
        } catch (TwirpException) {
        }

        self::assertSame(1, $http->requestCount());
    }

    public function test_a_short_request_timeout_disables_failover(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->twirpErrorResponse(503, 'unavailable'));

        try {
            // Under Failover::MIN_TIMEOUT_SECONDS a retry is unlikely to finish, and
            // every client would retry in lockstep across regions.
            $this->transport($http, new ClientOptions(requestTimeout: 2, failoverBackoffMs: 0))
                ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');
            self::fail('Expected a TwirpException');
        } catch (TwirpException) {
        }

        self::assertSame(1, $http->requestCount());
    }

    public function test_the_original_error_survives_when_no_region_is_left_to_try(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->twirpErrorResponse(503, 'unavailable', 'primary is down'));
        // Only the primary is advertised, so there is nowhere to fail over to.
        $http->pushResponse($this->regionsResponse('https://example.livekit.cloud'));

        try {
            $this->transport($http, $this->noBackoff())
                ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');
            self::fail('Expected a TwirpException');
        } catch (TwirpException $e) {
            self::assertSame('unavailable', $e->getTwirpCode());
            self::assertStringContainsString('primary is down', $e->getMessage());
        }

        self::assertSame(2, $http->requestCount());
    }

    public function test_a_failed_region_discovery_surfaces_the_original_error(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->twirpErrorResponse(503, 'unavailable', 'primary is down'));
        $http->pushResponse(new Response(500, [], 'discovery exploded'));

        try {
            $this->transport($http, $this->noBackoff())
                ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');
            self::fail('Expected a TwirpException');
        } catch (TwirpException $e) {
            self::assertStringContainsString(
                'primary is down',
                $e->getMessage(),
                'A failure while recovering must not replace the failure being recovered from.'
            );
        }
    }

    public function test_the_region_list_is_discovered_once_and_then_cached(): void
    {
        $cache = new RegionCache();

        $http = new MockHttpClient();
        $http->pushResponse($this->twirpErrorResponse(503, 'unavailable'));
        $http->pushResponse($this->regionsResponse('https://fallback.livekit.cloud'));
        $http->pushResponse($this->roomResponse('first'));
        $http->pushResponse($this->twirpErrorResponse(503, 'unavailable'));
        $http->pushResponse($this->roomResponse('second'));

        $transport = $this->transport($http, $this->noBackoff(), 'https://example.livekit.cloud', $cache);

        $transport->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');
        $transport->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');

        $discoveries = array_filter(
            $http->requests(),
            static fn ($r): bool => str_ends_with((string) $r->getUri(), '/settings/regions')
        );

        self::assertCount(1, $discoveries, 'Cache-Control allowed 60s; the second failover reused the list.');
    }

    // ---------------------------------------------------------------------
    // Region pinning (HTTP 451)
    //
    // A pinned project reaching a region it is not pinned to is turned away by
    // middleware before the request is served. That is a redirect, not a failure,
    // so it is followed even when failover is off -- but it still sends the
    // caller's token to a host named by a server response, so it obeys the same
    // domain guard.
    // ---------------------------------------------------------------------

    private function regionPinResponse(): Response
    {
        // Plain text, as the middleware writes it: not a Twirp error envelope.
        return new Response(
            Failover::REGION_PIN_STATUS,
            ['Content-Type' => 'text/plain; charset=utf-8'],
            'project not allowed in this region.'
        );
    }

    public function test_a_region_pin_redirects_to_an_allowed_region(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->regionPinResponse());
        $http->pushResponse($this->regionsResponse('https://allowed.livekit.cloud'));
        $http->pushResponse($this->roomResponse('my-room'));

        $room = $this->transport($http, $this->noBackoff())
            ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');

        self::assertSame('my-room', $room->getName());

        self::assertSame([
            'https://example.livekit.cloud/twirp/livekit.RoomService/CreateRoom',
            'https://example.livekit.cloud/settings/regions',
            'https://allowed.livekit.cloud/twirp/livekit.RoomService/CreateRoom',
        ], array_map(static fn ($r): string => (string) $r->getUri(), $http->requests()));
    }

    public function test_a_region_pin_redirects_even_with_failover_disabled(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->regionPinResponse());
        $http->pushResponse($this->regionsResponse('https://allowed.livekit.cloud'));
        $http->pushResponse($this->roomResponse('my-room'));

        $room = $this->transport($http, new ClientOptions(failover: false, failoverBackoffMs: 0))
            ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');

        // failover:false means "do not retry my failed requests elsewhere". Nothing
        // failed here: a pinned project has no other region that would answer, so
        // refusing to follow the redirect would only turn a working call into a 451.
        self::assertSame('my-room', $room->getName());
        self::assertSame(3, $http->requestCount());
    }

    public function test_a_region_pin_is_not_followed_off_livekit_cloud(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->regionPinResponse());

        try {
            $this->transport($http, $this->noBackoff(), 'https://livekit.example.com')
                ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');
            self::fail('Expected a TwirpException');
        } catch (TwirpException $e) {
            self::assertSame(Failover::REGION_PIN_STATUS, $e->getHttpStatus());
        }

        // Region pinning is a LiveKit Cloud mechanism, so a 451 from anywhere else
        // is something other than a redirect. Following it would send the token to
        // a host named by whatever produced it.
        self::assertSame(1, $http->requestCount());
    }

    public function test_a_region_pin_discards_the_cached_region_list(): void
    {
        $cache = new RegionCache();

        $http = new MockHttpClient();
        // A failover fills the cache with a 60-second list...
        $http->pushResponse($this->twirpErrorResponse(503, 'unavailable'));
        $http->pushResponse($this->regionsResponse('https://fallback.livekit.cloud'));
        $http->pushResponse($this->roomResponse('first'));
        // ...then a pin says that list is wrong, whatever its lifetime claimed.
        $http->pushResponse($this->regionPinResponse());
        $http->pushResponse($this->regionsResponse('https://pinned.livekit.cloud'));
        $http->pushResponse($this->roomResponse('second'));

        $transport = $this->transport($http, $this->noBackoff(), 'https://example.livekit.cloud', $cache);

        $transport->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');
        $second = $transport->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');

        self::assertSame('second', $second->getName());

        $uris = array_map(static fn ($r): string => (string) $r->getUri(), $http->requests());

        self::assertSame(
            2,
            count(array_filter($uris, static fn (string $u): bool => str_ends_with($u, '/settings/regions'))),
            'The pin forced a fresh discovery rather than reusing the cached list.'
        );

        self::assertStringStartsWith('https://pinned.livekit.cloud/', $uris[5]);
    }

    public function test_the_cached_list_is_replaced_rather_than_merely_bypassed(): void
    {
        $cache = new RegionCache();

        $http = new MockHttpClient();
        $http->pushResponse($this->twirpErrorResponse(503, 'unavailable'));
        $http->pushResponse($this->regionsResponse('https://fallback.livekit.cloud'));
        $http->pushResponse($this->roomResponse('first'));
        $http->pushResponse($this->regionPinResponse());
        $http->pushResponse($this->regionsResponse('https://pinned.livekit.cloud'));
        $http->pushResponse($this->roomResponse('second'));

        $transport = $this->transport($http, $this->noBackoff(), 'https://example.livekit.cloud', $cache);
        $transport->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');
        $transport->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');

        // The stale entry is dropped, not stepped around, so nothing later in the
        // process goes on using a list the server has already contradicted.
        self::assertSame(
            ['https://pinned.livekit.cloud'],
            $cache->get(Failover::hostKey('https://example.livekit.cloud'))
        );
    }

    public function test_redirects_are_bounded(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->regionPinResponse());
        $http->pushResponse($this->regionsResponse('https://a.livekit.cloud', 'https://b.livekit.cloud'));
        $http->pushResponse($this->regionPinResponse());
        $http->pushResponse($this->regionsResponse('https://a.livekit.cloud', 'https://b.livekit.cloud'));
        $http->pushResponse($this->regionPinResponse());

        try {
            $this->transport($http, $this->noBackoff())
                ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');
            self::fail('Expected a TwirpException');
        } catch (TwirpException $e) {
            self::assertSame(Failover::REGION_PIN_STATUS, $e->getHttpStatus());
        }

        // A server that keeps redirecting is a server something is wrong with;
        // looping is worse than surfacing the 451.
        self::assertSame(
            1 + Failover::MAX_PIN_REDIRECTS,
            count(array_filter(
                $http->requests(),
                static fn ($r): bool => str_contains((string) $r->getUri(), '/twirp/')
            ))
        );
    }

    // ---------------------------------------------------------------------
    // Hardening: things that must not escape, and inputs that must not be
    // passed on to the server as if they meant something.
    // ---------------------------------------------------------------------

    public function test_a_malformed_protobuf_response_is_still_a_livekit_exception(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse(new Response(200, ['Content-Type' => 'application/protobuf'], "\x01\x02\x03bogus"));

        try {
            $this->transport($http, $this->noBackoff())
                ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');
            self::fail('Expected the decode to fail');
        } catch (\Throwable $e) {
            // The protobuf runtime throws GPBDecodeException. Letting that out would
            // break the promise that catching LiveKitException catches everything --
            // on the default wire format, which makes it the likeliest path of all.
            self::assertInstanceOf(LiveKitException::class, $e);
            self::assertInstanceOf(TwirpException::class, $e);
            self::assertSame('internal', $e->getTwirpCode());
            self::assertNotNull($e->getPrevious());
        }
    }

    public function test_a_malformed_json_response_is_still_a_livekit_exception(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse(new Response(200, ['Content-Type' => 'application/json'], '{"not json'));

        try {
            $this->transport($http, new ClientOptions(wireFormat: WireFormat::Json, failoverBackoffMs: 0))
                ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');
            self::fail('Expected the decode to fail');
        } catch (\Throwable $e) {
            self::assertInstanceOf(LiveKitException::class, $e);
        }
    }

    public function test_the_token_never_reaches_a_region_outside_livekit_cloud(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->twirpErrorResponse(503, 'unavailable', 'primary is down'));
        // /settings/regions is a server response like any other. If it names a host
        // that is not LiveKit's, replaying there would hand over the caller's token.
        $http->pushResponse($this->regionsResponse('https://attacker.example.com'));

        try {
            $this->transport($http, $this->noBackoff())
                ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');
            self::fail('Expected a TwirpException');
        } catch (TwirpException $e) {
            self::assertStringContainsString('primary is down', $e->getMessage());
        }

        foreach ($http->requests() as $request) {
            self::assertStringContainsString(
                '.livekit.cloud',
                $request->getUri()->getHost(),
                'A request left for a host outside LiveKit Cloud.'
            );
        }
    }

    /** @return iterable<string, array{int}> */
    public static function meaninglessTimeouts(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
    }

    #[DataProvider('meaninglessTimeouts')]
    public function test_a_non_positive_timeout_is_not_sent_as_a_deadline(int $timeout): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->roomResponse('my-room'));

        $this->transport($http, new ClientOptions(requestTimeout: $timeout, failoverBackoffMs: 0))
            ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');

        // "0" would tell the server it has no time at all, and a negative value is
        // not a deadline. Saying nothing lets the server apply its own.
        self::assertFalse($http->lastRequest()->hasHeader('X-Twirp-Timeout-Ms'));
    }

    public function test_a_positive_timeout_is_still_sent(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->roomResponse('my-room'));

        $this->transport($http, new ClientOptions(requestTimeout: 7, failoverBackoffMs: 0))
            ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');

        self::assertSame('7000', $http->lastRequest()->getHeaderLine('X-Twirp-Timeout-Ms'));
    }

    /** @return iterable<string, array{string}> */
    public static function unusableHosts(): iterable
    {
        yield 'scheme but no host' => ['https://'];
        yield 'scheme only, no slashes' => ['https:'];
        yield 'a bare path' => ['/twirp'];
        // parse_url reads this as host + port, so an authority check alone lets it
        // through -- and the request then goes out with "localhost" as its scheme.
        yield 'host and port, no scheme' => ['localhost:7880'];
        yield 'a bare hostname' => ['my-project.livekit.cloud'];
        yield 'a scheme we cannot speak' => ['ftp://x.livekit.cloud'];
    }

    #[DataProvider('unusableHosts')]
    public function test_a_host_that_cannot_address_a_server_is_rejected_at_construction(string $host): void
    {
        // Left alone, these produce a nonsense URI and fail somewhere far from the
        // cause -- "https://" would post to "https:/twirp/livekit.RoomService/...".
        $this->expectException(ConfigurationException::class);

        $this->transport(new MockHttpClient(), null, $host);
    }

    /** @return iterable<string, array{string}> */
    public static function usableHosts(): iterable
    {
        yield 'https' => ['https://x.livekit.cloud'];
        yield 'http with a port' => ['http://127.0.0.1:7880'];
        yield 'wss, rewritten' => ['wss://x.livekit.cloud'];
        yield 'ws, rewritten' => ['ws://127.0.0.1:7880'];
        yield 'uppercase scheme' => ['HTTPS://x.livekit.cloud'];
    }

    #[DataProvider('usableHosts')]
    public function test_a_usable_host_is_accepted(string $host): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->roomResponse('my-room'));

        $this->transport($http, $this->noBackoff(), $host)
            ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');

        $uri = $http->lastRequest()->getUri();
        self::assertContains($uri->getScheme(), ['http', 'https'], 'ws(s) must be rewritten to http(s).');
    }

    public function test_a_redirect_is_reported_as_an_intermediary_with_its_location(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse(new Response(302, ['Location' => 'https://elsewhere.example.com/twirp'], ''));

        try {
            $this->transport($http, $this->noBackoff())
                ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');
            self::fail('Expected a TwirpException');
        } catch (TwirpException $e) {
            // PSR-18 clients do not follow redirects -- Guzzle disables them in
            // sendRequest() -- so a 3xx really does arrive here. Twirp only speaks
            // POST, which makes a redirect something in the middle rather than the
            // service, and where it pointed is the part worth reporting.
            self::assertSame(TwirpErrorCode::INTERNAL, $e->getTwirpCode());
            self::assertSame('https://elsewhere.example.com/twirp', $e->getMeta()['location'] ?? null);
            self::assertSame('true', $e->getMeta()[TwirpErrorCode::META_FROM_INTERMEDIARY] ?? null);
        }

        self::assertSame(1, $http->requestCount(), 'A redirect is not something to retry.');
    }

    public function test_a_proxy_failure_is_still_retried_when_its_status_says_so(): void
    {
        $http = new MockHttpClient();
        // A load balancer's HTML 503, not a Twirp envelope. The status is what makes
        // it retryable, and the mapped code is what a caller sees.
        $http->pushResponse(new Response(503, ['Content-Type' => 'text/html'], '<html>Service Unavailable</html>'));
        $http->pushResponse($this->regionsResponse('https://fallback.livekit.cloud'));
        $http->pushResponse($this->roomResponse('my-room'));

        $room = $this->transport($http, $this->noBackoff())
            ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');

        self::assertSame('my-room', $room->getName());
        self::assertSame(3, $http->requestCount());
    }

    public function test_an_auth_proxy_rejection_is_not_retried_and_says_what_it_was(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse(new Response(401, ['Content-Type' => 'text/plain'], 'Unauthorized'));

        try {
            $this->transport($http, $this->noBackoff())
                ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');
            self::fail('Expected a TwirpException');
        } catch (TwirpException $e) {
            self::assertSame(TwirpErrorCode::UNAUTHENTICATED, $e->getTwirpCode());
            self::assertSame('true', $e->getMeta()[TwirpErrorCode::META_FROM_INTERMEDIARY] ?? null);
        }

        self::assertSame(1, $http->requestCount());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function responseContentTypes(): iterable
    {
        yield 'the other encoding' => ['application/json', true];
        yield 'html from a gateway' => ['text/html', true];
        yield 'the one requested' => ['application/protobuf', false];
        yield 'the one requested, with a charset' => ['application/protobuf; charset=binary', false];
        yield 'none at all' => ['', false];
    }

    /**
     * The Twirp spec says a response's Content-Type must match the request's, so a
     * mismatch is the likeliest reason a body will not parse. "Could not decode"
     * alone sends people to look at their own message definitions; naming the
     * mismatch points at the gateway that actually caused it.
     */
    #[DataProvider('responseContentTypes')]
    public function test_a_decode_failure_names_a_content_type_mismatch(string $contentType, bool $expectNote): void
    {
        $http = new MockHttpClient();
        $http->pushResponse(new Response(200, $contentType === '' ? [] : ['Content-Type' => $contentType], '{"name":"x"}'));

        try {
            $this->transport($http, $this->noBackoff())
                ->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');
            self::fail('Expected the decode to fail');
        } catch (TwirpException $e) {
            $expectNote
                ? self::assertStringContainsString('was requested', $e->getMessage())
                : self::assertStringNotContainsString('was requested', $e->getMessage());
        }
    }

    public function test_an_empty_host_throws_a_configuration_exception(): void
    {
        $this->expectException(ConfigurationException::class);

        $this->transport(new MockHttpClient(), null, '');
    }

    public function test_a_whitespace_only_host_throws_a_configuration_exception(): void
    {
        $this->expectException(ConfigurationException::class);

        $this->transport(new MockHttpClient(), null, '   ');
    }

    public function test_generates_a_distinct_request_id_per_call(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->roomResponse('a'))->pushResponse($this->roomResponse('b'));

        $transport = $this->transport($http);
        $transport->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');
        $transport->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');

        $requests = $http->requests();

        self::assertNotSame(
            $requests[0]->getHeaderLine('X-Livekit-Request-Id'),
            $requests[1]->getHeaderLine('X-Livekit-Request-Id')
        );
    }
}
