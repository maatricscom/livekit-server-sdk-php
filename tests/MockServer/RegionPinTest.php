<?php

declare(strict_types=1);

namespace LiveKit\Tests\MockServer;

use LiveKit\Exceptions\TwirpException;
use LiveKit\Http\Failover;
use LiveKit\LiveKitAPI;
use LiveKit\Options\ClientOptions;
use LiveKit\Options\CreateRoomOptions;
use LiveKit\Tests\MockServer\Support\MockServerTestCase;

/**
 * Region pinning: HTTP 451 and what the client does about it.
 *
 * A LiveKit Cloud project can be pinned to a set of regions. Reaching one it is
 * not pinned to gets the request turned away by middleware with a 451, before it
 * is served -- the body is plain text, not a Twirp envelope, and it names no
 * destination. A pinned project's /settings/regions lists only the regions it is
 * allowed, so rediscovering is what turns the rejection into somewhere to go.
 *
 * The mock addresses pins by region *name* (`region-0`, `region-1`, ...), unlike
 * failRegions which addresses listeners by index.
 *
 * No official LiveKit SDK implements this yet; the mock specifies it and this is
 * written against that specification.
 */
final class RegionPinTest extends MockServerTestCase
{
    /**
     * @param array<string, mixed> $mock
     */
    private function pinnedApi(array $mock, bool $failover = true): LiveKitAPI
    {
        return $this->api($mock, new ClientOptions(
            failover: $failover,
            failoverForce: true,
            failoverBackoffMs: 0,
        ));
    }

    public function test_a_pinned_project_is_sent_to_a_region_it_is_allowed(): void
    {
        $room = $this->pinnedApi(['pinnedRegions' => ['region-1']])
            ->room->createRoom(new CreateRoomOptions(name: 'pinned-room'));

        self::assertSame('pinned-room', $room->getName());

        self::assertSame(
            [
                '127.0.0.1:9999',   // the primary, which the project is not pinned to
                '127.0.0.1:9999',   // GET /settings/regions, which lists only region-1
                '127.0.0.1:10000',  // region-1, which served the call
            ],
            $this->http->hosts()
        );

        self::assertSame('1', $this->http->servingRegions()[2]);
    }

    public function test_the_redirect_still_happens_with_failover_switched_off(): void
    {
        // Turning off failover says "do not retry my failed requests elsewhere". A
        // pin redirect is not a retry: the request was never served, and no other
        // region will serve it either. Declining to follow it would turn a working
        // call into a 451 with nothing gained.
        $room = $this->pinnedApi(['pinnedRegions' => ['region-1']], failover: false)
            ->room->createRoom(new CreateRoomOptions(name: 'pinned-room'));

        self::assertSame('pinned-room', $room->getName());
        self::assertSame('1', $this->http->servingRegions()[2]);
    }

    public function test_a_redirect_leaves_the_failover_budget_intact(): void
    {
        // Pinned to three regions, the first two of which are also made to fail, so
        // the call needs one pin redirect *and* both failover retries. If the
        // redirect were charged to the failover budget the last region would never
        // be reached -- which is the whole point of the scenario: a weaker one
        // (fewer failures) passes whether or not the budgets are shared, and proves
        // nothing.
        $room = $this->pinnedApi([
            'pinnedRegions' => ['region-1', 'region-2', 'region-3'],
            'failRegions' => [1, 2],
        ])->room->createRoom(new CreateRoomOptions(name: 'pinned-room'));

        self::assertSame('pinned-room', $room->getName());

        self::assertSame(
            [
                '127.0.0.1:9999',   // not pinned here -> 451, redirect
                '127.0.0.1:9999',   // discovery: regions 1, 2 and 3
                '127.0.0.1:10000',  // region-1, pinned but failing -> failover
                '127.0.0.1:10001',  // region-2, pinned but failing -> failover
                '127.0.0.1:10002',  // region-3, which served the call
            ],
            $this->http->hosts()
        );

        self::assertSame('3', $this->http->servingRegions()[4]);
    }

    public function test_the_allowed_region_list_is_discovered_only_once(): void
    {
        $this->pinnedApi([
            'pinnedRegions' => ['region-1', 'region-2', 'region-3'],
            'failRegions' => [1, 2],
        ])->room->createRoom(new CreateRoomOptions(name: 'pinned-room'));

        $discoveries = array_filter(
            $this->http->uris(),
            static fn (string $uri): bool => str_ends_with($uri, '/settings/regions')
        );

        // The failover that follows the redirect reuses the pinned list rather than
        // asking again. Under a pin those are the only regions that answer at all,
        // so a second fetch could only return the same thing.
        self::assertCount(1, $discoveries);
    }

    public function test_a_pin_to_a_region_nobody_serves_surfaces_the_rejection(): void
    {
        try {
            // /settings/regions filters to the pinned names, and no listener
            // advertises this one, so discovery comes back with nowhere to go.
            $this->pinnedApi(['pinnedRegions' => ['region-99']])
                ->room->createRoom(new CreateRoomOptions(name: 'pinned-room'));
            self::fail('Expected the call to throw.');
        } catch (TwirpException $e) {
            self::assertSame(Failover::REGION_PIN_STATUS, $e->getHttpStatus());
            self::assertStringContainsString('not allowed in this region', $e->getMessage());
        }

        self::assertSame(
            ['127.0.0.1:9999', '127.0.0.1:9999'],
            $this->http->hosts(),
            'One attempt and one discovery; there was no third host to try.'
        );
    }
}
