<?php

declare(strict_types=1);

namespace LiveKit;

use LiveKit\Http\HttpClientResolver;
use LiveKit\Services\AgentDispatchClient;
use LiveKit\Services\ConnectorClient;
use LiveKit\Services\EgressClient;
use LiveKit\Services\IngressClient;
use LiveKit\Services\RoomServiceClient;
use LiveKit\Services\SipClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Convenience facade over the individual service clients.
 *
 * Named to match LiveKit's other server SDKs, which all expose this entry point
 * under the same name — LiveKitAPI in Node, LiveKit::API in Ruby, livekit-api in
 * Python — so the documentation reads the same whichever language you arrive from.
 *
 * Every service client can also be constructed on its own; this exists so a
 * single set of credentials and one HTTP client serve all of them.
 */
final class LiveKitAPI
{
    public readonly RoomServiceClient $room;

    public readonly EgressClient $egress;

    public readonly IngressClient $ingress;

    public readonly SipClient $sip;

    public readonly AgentDispatchClient $agentDispatch;

    /** LiveKit Cloud only: the open-source server does not implement livekit.Connector. */
    public readonly ConnectorClient $connector;

    public function __construct(
        ?string $host = null,
        ?string $apiKey = null,
        ?string $apiSecret = null,
        ?ClientOptions $options = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        // Resolve once so every client shares one HTTP client and factory pair
        // instead of running discovery per client.
        $httpClient = HttpClientResolver::client($httpClient);
        $requestFactory = HttpClientResolver::requestFactory($requestFactory);
        $streamFactory = HttpClientResolver::streamFactory($streamFactory);

        $args = [$host, $apiKey, $apiSecret, $options, $httpClient, $requestFactory, $streamFactory];

        $this->room = new RoomServiceClient(...$args);
        $this->egress = new EgressClient(...$args);
        $this->ingress = new IngressClient(...$args);
        $this->sip = new SipClient(...$args);
        $this->agentDispatch = new AgentDispatchClient(...$args);
        $this->connector = new ConnectorClient(...$args);
    }
}
