<?php

declare(strict_types=1);

namespace LiveKit\Tests\Http;

use LiveKit\Http\HttpClientResolver;
use LiveKit\Tests\Support\MockHttpClient;
use LiveKit\Tests\Support\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class HttpClientResolverTest extends TestCase
{
    public function test_returns_the_injected_client_unchanged(): void
    {
        $client = new MockHttpClient();

        self::assertSame($client, HttpClientResolver::client($client));
    }

    public function test_discovers_a_client_when_none_is_injected(): void
    {
        $client = HttpClientResolver::client(null);

        self::assertInstanceOf(\Psr\Http\Client\ClientInterface::class, $client);
    }

    public function test_discovers_the_psr17_factories(): void
    {
        self::assertInstanceOf(RequestFactoryInterface::class, HttpClientResolver::requestFactory(null));
        self::assertInstanceOf(StreamFactoryInterface::class, HttpClientResolver::streamFactory(null));
    }

    public function test_returns_injected_factories_unchanged(): void
    {
        $requestFactory = HttpClientResolver::requestFactory(null);
        $streamFactory = HttpClientResolver::streamFactory(null);

        self::assertSame($requestFactory, HttpClientResolver::requestFactory($requestFactory));
        self::assertSame($streamFactory, HttpClientResolver::streamFactory($streamFactory));
    }
}
