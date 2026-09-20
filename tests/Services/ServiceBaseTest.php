<?php

declare(strict_types=1);

namespace LiveKit\Tests\Services;

use Google\Protobuf\Internal\Message;
use LiveKit\ClientOptions;
use LiveKit\Exceptions\ConfigurationException;
use LiveKit\Grants\SIPGrant;
use LiveKit\Grants\VideoGrant;
use LiveKit\Proto\CreateRoomRequest;
use LiveKit\Proto\Room;
use LiveKit\Services\ServiceBase;
use LiveKit\Tests\Support\MockHttpClient;
use LiveKit\Tests\Support\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;

/**
 * A minimal concrete subclass that exposes the protected members for testing.
 */
final class ServiceBaseProbe extends ServiceBase
{
    public function callAuthHeader(VideoGrant $video, ?SIPGrant $sip = null): string
    {
        return $this->authHeader($video, $sip);
    }

    /**
     * @template T of Message
     *
     * @param class-string<T> $responseClass
     *
     * @return T
     */
    public function callRpc(string $service, string $method, Message $request, string $responseClass, string $jwt): Message
    {
        return $this->rpc($service, $method, $request, $responseClass, $jwt);
    }
}

final class ServiceBaseTest extends TestCase
{
    private function probe(?MockHttpClient $http = null, ?ClientOptions $options = null): ServiceBaseProbe
    {
        $factory = new Psr17Factory();

        return new ServiceBaseProbe(
            'https://example.livekit.cloud',
            self::API_KEY,
            self::API_SECRET,
            $options,
            $http ?? new MockHttpClient(),
            $factory,
            $factory,
        );
    }

    public function test_mints_a_token_carrying_the_requested_video_grant(): void
    {
        $jwt = $this->probe()->callAuthHeader(new VideoGrant(roomCreate: true));

        $claims = $this->decodeJwtPayload($jwt);

        self::assertSame(self::API_KEY, $claims['iss']);
        self::assertSame(['roomCreate' => true], $claims['video']);
    }

    public function test_mints_a_token_carrying_both_video_and_sip_grants(): void
    {
        $jwt = $this->probe()->callAuthHeader(
            new VideoGrant(roomAdmin: true, room: 'my-room'),
            new SIPGrant(call: true),
        );

        $claims = $this->decodeJwtPayload($jwt);

        self::assertSame(['roomAdmin' => true, 'room' => 'my-room'], $claims['video']);
        self::assertSame(['call' => true], $claims['sip']);
    }

    public function test_service_tokens_live_for_ten_minutes(): void
    {
        $claims = $this->decodeJwtPayload($this->probe()->callAuthHeader(new VideoGrant(roomList: true)));

        $exp = $claims['exp'];
        $nbf = $claims['nbf'];
        self::assertIsInt($exp);
        self::assertIsInt($nbf);

        /**
         * @var int $exp
         * @var int $nbf
         */
        self::assertSame(600, $exp - $nbf);
    }

    public function test_a_pre_signed_token_is_used_verbatim_without_signing(): void
    {
        $probe = $this->probe(null, new ClientOptions(token: 'a-pre-signed-token'));

        self::assertSame('a-pre-signed-token', $probe->callAuthHeader(new VideoGrant(roomCreate: true)));
    }

    /**
     * A pre-signed token means the SDK can run with no secret at all.
     */
    public function test_works_without_credentials_when_a_pre_signed_token_is_supplied(): void
    {
        $factory = new Psr17Factory();

        $probe = new ServiceBaseProbe(
            'https://example.livekit.cloud',
            null,
            null,
            new ClientOptions(token: 'a-pre-signed-token'),
            new MockHttpClient(),
            $factory,
            $factory,
        );

        self::assertSame('a-pre-signed-token', $probe->callAuthHeader(new VideoGrant(roomCreate: true)));
    }

    public function test_requires_credentials_when_no_pre_signed_token_is_supplied(): void
    {
        putenv('LIVEKIT_API_KEY');
        putenv('LIVEKIT_API_SECRET');

        try {
            $this->expectException(ConfigurationException::class);

            new ServiceBaseProbe('https://example.livekit.cloud');
        } finally {
            putenv('LIVEKIT_API_KEY');
            putenv('LIVEKIT_API_SECRET');
        }
    }

    public function test_falls_back_to_environment_configuration(): void
    {
        putenv('LIVEKIT_URL=https://env.livekit.cloud');
        putenv('LIVEKIT_API_KEY=env-key');
        putenv('LIVEKIT_API_SECRET=env-secret-that-is-long-enough-yes');

        try {
            $http = new MockHttpClient();
            $room = new Room();
            $room->setName('r');
            $http->pushResponse(new Response(200, [], $room->serializeToString()));

            $factory = new Psr17Factory();
            $probe = new ServiceBaseProbe(null, null, null, null, $http, $factory, $factory);

            $probe->callRpc('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');

            self::assertStringStartsWith('https://env.livekit.cloud/', (string) $http->lastRequest()->getUri());
        } finally {
            putenv('LIVEKIT_URL');
            putenv('LIVEKIT_API_KEY');
            putenv('LIVEKIT_API_SECRET');
        }
    }

    public function test_rpc_delegates_to_the_transport(): void
    {
        $http = new MockHttpClient();
        $room = new Room();
        $room->setName('my-room');
        $http->pushResponse(new Response(200, [], $room->serializeToString()));

        $result = $this->probe($http)->callRpc('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');

        self::assertInstanceOf(Room::class, $result);
        self::assertSame('my-room', $result->getName());
        self::assertSame(
            'https://example.livekit.cloud/twirp/livekit.RoomService/CreateRoom',
            (string) $http->lastRequest()->getUri()
        );
    }
}
