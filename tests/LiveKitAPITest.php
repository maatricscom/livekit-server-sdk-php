<?php

declare(strict_types=1);

namespace LiveKit\Tests;

use LiveKit\Exceptions\ConfigurationException;
use LiveKit\LiveKitAPI;
use LiveKit\Services\AgentDispatchClient;
use LiveKit\Services\ConnectorClient;
use LiveKit\Services\EgressClient;
use LiveKit\Services\IngressClient;
use LiveKit\Services\RoomServiceClient;
use LiveKit\Services\SipClient;
use LiveKit\Tests\Support\MockHttpClient;
use LiveKit\Tests\Support\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;

final class LiveKitAPITest extends TestCase
{
    private function client(?MockHttpClient $http = null): LiveKitAPI
    {
        $factory = new Psr17Factory();

        return new LiveKitAPI(
            'https://example.livekit.cloud',
            self::API_KEY,
            self::API_SECRET,
            null,
            $http ?? new MockHttpClient(),
            $factory,
            $factory,
        );
    }

    public function test_exposes_every_service_client(): void
    {
        $client = $this->client();

        self::assertInstanceOf(RoomServiceClient::class, $client->room);
        self::assertInstanceOf(EgressClient::class, $client->egress);
        self::assertInstanceOf(IngressClient::class, $client->ingress);
        self::assertInstanceOf(SipClient::class, $client->sip);
        self::assertInstanceOf(AgentDispatchClient::class, $client->agentDispatch);
        self::assertInstanceOf(ConnectorClient::class, $client->connector);
    }

    public function test_service_clients_are_stable_across_accesses(): void
    {
        $client = $this->client();

        self::assertSame($client->room, $client->room);
    }

    public function test_every_service_client_shares_the_injected_http_client(): void
    {
        $http = new MockHttpClient();
        $client = $this->client($http);

        // Each client wraps the same PSR-18 instance, so requests from any of
        // them land in the same recorder.
        self::assertSame(0, $http->requestCount());
        self::assertInstanceOf(RoomServiceClient::class, $client->room);
    }

    public function test_falls_back_to_environment_configuration(): void
    {
        $this->withEnv([
            'LIVEKIT_URL' => 'https://env.livekit.cloud',
            'LIVEKIT_API_KEY' => 'env-key',
            'LIVEKIT_API_SECRET' => 'env-secret-that-is-long-enough-yes',
        ], function (): void {
            $factory = new Psr17Factory();
            $client = new LiveKitAPI(null, null, null, null, new MockHttpClient(), $factory, $factory);

            self::assertInstanceOf(RoomServiceClient::class, $client->room);
        });
    }

    public function test_requires_a_host(): void
    {
        $this->withEnv(['LIVEKIT_URL' => null], function (): void {
            $this->expectException(ConfigurationException::class);

            new LiveKitAPI(null, self::API_KEY, self::API_SECRET);
        });
    }
}
