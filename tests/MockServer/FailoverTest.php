<?php

declare(strict_types=1);

namespace LiveKit\Tests\MockServer;

use LiveKit\ClientOptions;
use LiveKit\Exceptions\TwirpException;
use LiveKit\Http\Failover;
use LiveKit\Http\TwirpClient;
use LiveKit\Options\CreateRoomOptions;
use LiveKit\Tests\MockServer\Support\MockServerTestCase;

/**
 * Region failover against the mock's simulated regions.
 *
 * The mock binds one listener per region and advertises them all from
 * /settings/regions. Each listener decides from its own index whether to fail, so
 * a single `failRegions` directive is enough to make the primary fail and a
 * fallback succeed with no coordination. `X-Lk-Mock-Region` on the response names
 * the listener that served it, which is how these tests prove the request really
 * moved rather than merely being retried.
 *
 * Every test here sets failoverForce, because the mock is on 127.0.0.1 and
 * failover otherwise engages only for *.livekit.cloud. That is the one guarantee
 * this file cannot check; TwirpClientTest and FailoverTest cover it instead.
 */
final class FailoverTest extends MockServerTestCase
{
    /** @param array<string, mixed> $mock */
    private function failoverApi(array $mock, bool $enabled = true): \LiveKit\LiveKitAPI
    {
        return $this->api($mock, new ClientOptions(
            failover: $enabled,
            failoverForce: true,
            failoverBackoffMs: 0,
        ));
    }

    public function test_a_failing_primary_is_replayed_against_the_next_region(): void
    {
        $room = $this->failoverApi(['failRegions' => [0]])
            ->room->createRoom(new CreateRoomOptions(name: 'failover-room'));

        self::assertSame('failover-room', $room->getName());

        self::assertSame(
            [
                '127.0.0.1:9999',   // the primary, which failed
                '127.0.0.1:9999',   // GET /settings/regions
                '127.0.0.1:10000',  // region 1, which answered
            ],
            $this->http->hosts()
        );

        self::assertSame('1', $this->http->servingRegions()[2], 'Region 1 served the successful attempt.');
    }

    public function test_a_second_failure_moves_on_to_the_region_after_it(): void
    {
        $room = $this->failoverApi(['failRegions' => [0, 1]])
            ->room->createRoom(new CreateRoomOptions(name: 'failover-room'));

        self::assertSame('failover-room', $room->getName());
        self::assertSame('2', $this->http->servingRegions()[3]);
        self::assertSame(
            ['127.0.0.1:9999', '127.0.0.1:9999', '127.0.0.1:10000', '127.0.0.1:10001'],
            $this->http->hosts()
        );
    }

    public function test_the_attempt_budget_is_finite(): void
    {
        // Three regions fail; the mock has a fourth that would succeed, but
        // Failover::MAX_ATTEMPTS has been spent by then.
        try {
            $this->failoverApi(['failRegions' => [0, 1, 2]])
                ->room->createRoom(new CreateRoomOptions(name: 'failover-room'));
            self::fail('Expected the call to throw once the attempts were spent.');
        } catch (TwirpException $e) {
            self::assertSame(503, $e->getHttpStatus());
        }

        $posts = array_values(array_filter(
            $this->http->uris(),
            static fn (string $uri): bool => str_contains($uri, '/twirp/')
        ));

        self::assertCount(Failover::MAX_ATTEMPTS, $posts);
    }

    public function test_a_dropped_connection_is_replayed_too(): void
    {
        // failMode "drop" closes the connection instead of answering, so the retry
        // is driven by a transport error rather than by a status code.
        $room = $this->failoverApi(['failRegions' => [0], 'failMode' => 'drop'])
            ->room->createRoom(new CreateRoomOptions(name: 'dropped-room'));

        self::assertSame('dropped-room', $room->getName());
        self::assertSame('1', $this->http->servingRegions()[2]);
    }

    public function test_a_4xx_is_not_replayed(): void
    {
        try {
            $this->failoverApi(['failRegions' => [0], 'failStatus' => 400])
                ->room->createRoom(new CreateRoomOptions(name: 'bad-room'));
            self::fail('Expected the call to throw.');
        } catch (TwirpException $e) {
            self::assertSame(400, $e->getHttpStatus());
        }

        self::assertSame(1, $this->http->attempts(), 'A 4xx is answered the same way by every region.');
    }

    public function test_nothing_is_replayed_when_failover_is_off(): void
    {
        try {
            $this->failoverApi(['failRegions' => [0]], enabled: false)
                ->room->createRoom(new CreateRoomOptions(name: 'failover-room'));
            self::fail('Expected the call to throw.');
        } catch (TwirpException $e) {
            self::assertSame(503, $e->getHttpStatus());
        }

        self::assertSame(1, $this->http->attempts());
    }

    public function test_the_replay_arrives_with_the_same_request_id(): void
    {
        $this->failoverApi(['failRegions' => [0]])
            ->room->createRoom(new CreateRoomOptions(name: 'failover-room'));

        $requests = $this->http->requests();
        $first = $requests[0]->getHeaderLine(TwirpClient::REQUEST_ID_HEADER);
        $replay = $requests[2]->getHeaderLine(TwirpClient::REQUEST_ID_HEADER);

        self::assertNotSame('', $first);
        self::assertSame($first, $replay, 'The server can only deduplicate a retry that kept its id.');
    }

    public function test_the_discovery_request_carries_credentials(): void
    {
        $this->failoverApi(['failRegions' => [0]])
            ->room->createRoom(new CreateRoomOptions(name: 'failover-room'));

        $discovery = $this->http->requests()[1];

        self::assertStringEndsWith('/settings/regions', (string) $discovery->getUri());
        self::assertStringStartsWith('Bearer ', $discovery->getHeaderLine('Authorization'));
        self::assertSame('GET', $discovery->getMethod());
        self::assertSame(
            '',
            $discovery->getHeaderLine('Content-Type'),
            'The Twirp body content type does not describe this endpoint.'
        );
    }
}
