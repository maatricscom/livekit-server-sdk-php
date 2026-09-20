<?php

declare(strict_types=1);

namespace LiveKit\Tests\MockServer\Support;

use LiveKit\ClientOptions;
use LiveKit\Http\HttpClientResolver;
use LiveKit\Http\RegionCache;
use LiveKit\LiveKitAPI;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that run against livekit/test-server -- the programmable
 * mock of the LiveKit HTTP API that every official server SDK tests against.
 *
 * These are not unit tests with a stubbed transport: a real HTTP request leaves
 * the process and a real Twirp server answers it. That is the point. Three things
 * are only provable this way:
 *
 *   1. that our binary application/protobuf request body is one LiveKit accepts,
 *   2. that the VideoGrant we mint for each RPC satisfies the server's own
 *      permission table, and
 *   3. that a real Twirp error envelope maps onto our exception types.
 *
 * Skipped unless LIVEKIT_TEST_SERVER_URL and LIVEKIT_TEST_SERVER_SECRET are both
 * set, so it never gates the unit suite. See CONTRIBUTING.md for how to run it.
 */
abstract class MockServerTestCase extends TestCase
{
    protected const API_KEY = 'devkey';

    /** The decorator wrapping the most recent api() call, for asserting on the wire. */
    protected DirectiveHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['LIVEKIT_TEST_SERVER_URL', 'LIVEKIT_TEST_SERVER_SECRET'] as $name) {
            if (getenv($name) === false || getenv($name) === '') {
                self::markTestSkipped(sprintf(
                    '%s is not set; skipping mock-server tests. See CONTRIBUTING.md.',
                    $name
                ));
            }
        }

        // TwirpClient defaults to the process-wide cache, so a region list left
        // behind by one test would decide what the next one discovers.
        RegionCache::shared()->clear();
    }

    /**
     * Builds an API bound to the mock, optionally with X-Lk-Mock directives.
     *
     * @param array<string, mixed> $mock directives from cmd/test-server/README.md
     */
    protected function api(array $mock = [], ?ClientOptions $options = null): LiveKitAPI
    {
        $this->http = new DirectiveHttpClient(HttpClientResolver::client(null), $mock);

        return new LiveKitAPI(self::baseUrl(), self::API_KEY, self::apiSecret(), $options, $this->http);
    }

    protected static function baseUrl(): string
    {
        $url = getenv('LIVEKIT_TEST_SERVER_URL');
        self::assertIsString($url);

        return rtrim($url, '/');
    }

    /**
     * Must match the server's --api-secret. Our AccessToken rejects anything
     * shorter than 32 bytes, which rules out the `livekit-server --dev` default
     * of "secret" -- so the mock has to be started with a longer one.
     */
    protected static function apiSecret(): string
    {
        $secret = getenv('LIVEKIT_TEST_SERVER_SECRET');
        self::assertIsString($secret);

        return $secret;
    }
}
