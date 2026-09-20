<?php

declare(strict_types=1);

namespace LiveKit\Tests\Http;

use LiveKit\Http\RegionCache;
use LiveKit\Tests\Support\TestCase;

final class RegionCacheTest extends TestCase
{
    /**
     * A settable clock, so expiry is tested by moving time rather than by sleeping
     * through it. A test that has to wait out a TTL either takes the TTL or proves
     * nothing about it.
     */
    private float $now = 1_000.0;

    private function cache(): RegionCache
    {
        return new RegionCache(fn (): float => $this->now);
    }

    public function test_an_unknown_host_has_no_entry(): void
    {
        self::assertNull($this->cache()->get('x.livekit.cloud'));
    }

    public function test_a_stored_list_is_returned_within_its_lifetime(): void
    {
        $cache = $this->cache();
        $cache->put('x.livekit.cloud', ['https://a.livekit.cloud'], 60);

        $this->now += 59;

        self::assertSame(['https://a.livekit.cloud'], $cache->get('x.livekit.cloud'));
    }

    public function test_a_list_is_dropped_once_its_lifetime_is_up(): void
    {
        $cache = $this->cache();
        $cache->put('x.livekit.cloud', ['https://a.livekit.cloud'], 60);

        $this->now += 60;

        self::assertNull($cache->get('x.livekit.cloud'), 'The TTL is exclusive at its own boundary.');
    }

    public function test_a_non_positive_lifetime_stores_nothing(): void
    {
        $cache = $this->cache();

        // What Cache-Control: max-age=0 -- or no Cache-Control at all -- asks for.
        $cache->put('x.livekit.cloud', ['https://a.livekit.cloud'], 0);
        self::assertNull($cache->get('x.livekit.cloud'));

        $cache->put('x.livekit.cloud', ['https://a.livekit.cloud'], -5);
        self::assertNull($cache->get('x.livekit.cloud'));
    }

    public function test_entries_are_kept_per_host(): void
    {
        $cache = $this->cache();
        $cache->put('a.livekit.cloud', ['https://a1.livekit.cloud'], 60);
        $cache->put('b.livekit.cloud', ['https://b1.livekit.cloud'], 60);

        self::assertSame(['https://a1.livekit.cloud'], $cache->get('a.livekit.cloud'));
        self::assertSame(['https://b1.livekit.cloud'], $cache->get('b.livekit.cloud'));
    }

    public function test_a_later_put_replaces_an_earlier_one(): void
    {
        $cache = $this->cache();
        $cache->put('x.livekit.cloud', ['https://old.livekit.cloud'], 60);
        $cache->put('x.livekit.cloud', ['https://new.livekit.cloud'], 60);

        self::assertSame(['https://new.livekit.cloud'], $cache->get('x.livekit.cloud'));
    }

    public function test_clearing_empties_the_cache(): void
    {
        $cache = $this->cache();
        $cache->put('x.livekit.cloud', ['https://a.livekit.cloud'], 60);
        $cache->clear();

        self::assertNull($cache->get('x.livekit.cloud'));
    }

    public function test_the_shared_instance_is_the_same_one_every_time(): void
    {
        // TwirpClient defaults to it, so the five clients behind one LiveKitAPI
        // discover a project's regions once between them rather than once each.
        self::assertSame(RegionCache::shared(), RegionCache::shared());
    }
}
