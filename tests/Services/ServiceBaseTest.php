<?php

declare(strict_types=1);

namespace LiveKit\Tests\Services;

use Google\Protobuf\Internal\Message;
use LiveKit\Exceptions\ConfigurationException;
use LiveKit\Grants\SIPGrant;
use LiveKit\Grants\VideoGrant;
use LiveKit\Options\ClientOptions;
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
        $this->withEnv([
            'LIVEKIT_API_KEY' => null,
            'LIVEKIT_API_SECRET' => null,
            'LIVEKIT_TOKEN' => null,
        ], function (): void {
            $this->expectException(ConfigurationException::class);

            new ServiceBaseProbe('https://example.livekit.cloud');
        });
    }

    // ---------------------------------------------------------------------
    // Credential resolution
    //
    // The environment is read only when the caller supplied no credential at
    // all. Not field by field: completing an explicit API key with a secret from
    // the environment, or letting an ambient LIVEKIT_TOKEN stand in for
    // credentials that were passed in, is how a process authenticates as
    // something nobody chose.
    // ---------------------------------------------------------------------

    private const ENV_SECRET = 'env-secret-that-is-long-enough-ok';

    public function test_a_token_in_the_environment_is_a_complete_credential(): void
    {
        $this->withEnv([
            'LIVEKIT_API_KEY' => null,
            'LIVEKIT_API_SECRET' => null,
            'LIVEKIT_TOKEN' => 'ambient-token',
        ], function (): void {
            $probe = new ServiceBaseProbe('https://example.livekit.cloud');

            self::assertSame('ambient-token', $probe->callAuthHeader(new VideoGrant(roomCreate: true)));
        });
    }

    public function test_an_environment_token_wins_over_an_environment_key_and_secret(): void
    {
        $this->withEnv([
            'LIVEKIT_API_KEY' => 'env-key',
            'LIVEKIT_API_SECRET' => self::ENV_SECRET,
            'LIVEKIT_TOKEN' => 'ambient-token',
        ], function (): void {
            // A token is a complete credential on its own, so the key and secret
            // are never read -- there is no second candidate to choose between.
            $probe = new ServiceBaseProbe('https://example.livekit.cloud');

            self::assertSame('ambient-token', $probe->callAuthHeader(new VideoGrant(roomCreate: true)));
        });
    }

    public function test_an_explicit_key_is_not_completed_with_a_secret_from_the_environment(): void
    {
        $this->withEnv([
            'LIVEKIT_API_KEY' => null,
            'LIVEKIT_API_SECRET' => self::ENV_SECRET,
            'LIVEKIT_TOKEN' => null,
        ], function (): void {
            $this->expectException(ConfigurationException::class);

            new ServiceBaseProbe('https://example.livekit.cloud', 'explicit-key');
        });
    }

    public function test_an_explicit_secret_is_not_completed_with_a_key_from_the_environment(): void
    {
        $this->withEnv([
            'LIVEKIT_API_KEY' => 'env-key',
            'LIVEKIT_API_SECRET' => null,
            'LIVEKIT_TOKEN' => null,
        ], function (): void {
            $this->expectException(ConfigurationException::class);

            new ServiceBaseProbe('https://example.livekit.cloud', null, self::API_SECRET);
        });
    }

    public function test_an_ambient_token_does_not_stand_in_for_explicit_credentials(): void
    {
        $this->withEnv([
            'LIVEKIT_API_KEY' => null,
            'LIVEKIT_API_SECRET' => null,
            'LIVEKIT_TOKEN' => 'ambient-token',
        ], function (): void {
            $probe = new ServiceBaseProbe('https://example.livekit.cloud', self::API_KEY, self::API_SECRET);

            $header = $probe->callAuthHeader(new VideoGrant(roomCreate: true));

            self::assertNotSame('ambient-token', $header);
            self::assertSame(self::API_KEY, $this->decodeJwtPayload($header)['iss'] ?? null);
        });
    }

    public function test_an_explicit_token_is_not_overridden_by_environment_credentials(): void
    {
        $this->withEnv([
            'LIVEKIT_API_KEY' => 'env-key',
            'LIVEKIT_API_SECRET' => self::ENV_SECRET,
            'LIVEKIT_TOKEN' => null,
        ], function (): void {
            $probe = new ServiceBaseProbe(
                'https://example.livekit.cloud',
                null,
                null,
                new ClientOptions(token: 'explicit-token'),
            );

            self::assertSame('explicit-token', $probe->callAuthHeader(new VideoGrant(roomCreate: true)));
        });
    }

    public function test_a_blank_token_counts_as_no_token_at_all(): void
    {
        $this->withEnv([
            'LIVEKIT_API_KEY' => 'env-key',
            'LIVEKIT_API_SECRET' => self::ENV_SECRET,
            'LIVEKIT_TOKEN' => null,
        ], function (): void {
            // token: '' is not a credential, so nothing was supplied and the
            // environment is read as usual.
            $probe = new ServiceBaseProbe(
                'https://example.livekit.cloud',
                null,
                null,
                new ClientOptions(token: ''),
            );

            self::assertSame('env-key', $this->decodeJwtPayload($probe->callAuthHeader(new VideoGrant()))['iss'] ?? null);
        });
    }

    public function test_the_credentials_error_names_every_way_to_supply_them(): void
    {
        $this->withEnv([
            'LIVEKIT_API_KEY' => null,
            'LIVEKIT_API_SECRET' => null,
            'LIVEKIT_TOKEN' => null,
        ], function (): void {
            try {
                new ServiceBaseProbe('https://example.livekit.cloud');
                self::fail('Expected a ConfigurationException');
            } catch (ConfigurationException $e) {
                foreach (['LIVEKIT_API_KEY', 'LIVEKIT_API_SECRET', 'LIVEKIT_TOKEN'] as $name) {
                    self::assertStringContainsString($name, $e->getMessage());
                }
            }
        });
    }

    public function test_falls_back_to_environment_configuration(): void
    {
        $this->withEnv([
            'LIVEKIT_URL' => 'https://env.livekit.cloud',
            'LIVEKIT_API_KEY' => 'env-key',
            'LIVEKIT_API_SECRET' => 'env-secret-that-is-long-enough-yes',
        ], function (): void {
            $http = new MockHttpClient();
            $room = new Room();
            $room->setName('r');
            $http->pushResponse(new Response(200, [], $room->serializeToString()));

            $factory = new Psr17Factory();
            $probe = new ServiceBaseProbe(null, null, null, null, $http, $factory, $factory);

            $probe->callRpc('RoomService', 'CreateRoom', new CreateRoomRequest(), Room::class, 'jwt');

            self::assertStringStartsWith('https://env.livekit.cloud/', (string) $http->lastRequest()->getUri());
        });
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
