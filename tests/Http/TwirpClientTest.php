<?php

declare(strict_types=1);

namespace LiveKit\Tests\Http;

use LiveKit\ClientOptions;
use LiveKit\Enums\WireFormat;
use LiveKit\Exceptions\SipCallError;
use LiveKit\Exceptions\TwirpException;
use LiveKit\Http\TwirpClient;
use LiveKit\Proto\CreateRoomRequest;
use LiveKit\Proto\Room;
use LiveKit\Tests\Support\MockHttpClient;
use LiveKit\Tests\Support\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;

final class TwirpClientTest extends TestCase
{
    private function transport(MockHttpClient $http, ?ClientOptions $options = null, string $host = 'https://example.livekit.cloud'): TwirpClient
    {
        $factory = new Psr17Factory();

        return new TwirpClient($host, $options ?? new ClientOptions(), $http, $factory, $factory);
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

    public function test_uses_the_pre_signed_token_path_transparently(): void
    {
        $http = new MockHttpClient();
        $http->pushResponse($this->roomResponse('my-room'));

        $this->transport($http)->request('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'pre-signed');

        self::assertSame('Bearer pre-signed', $http->lastRequest()->getHeaderLine('Authorization'));
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
