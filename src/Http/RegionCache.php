<?php

declare(strict_types=1);

namespace LiveKit\Http;

/**
 * Remembers the region list LiveKit Cloud advertised for a host, so a failover
 * does not re-fetch /settings/regions on every retry.
 *
 * Shared across the clients built by one LiveKitAPI, because the region list
 * belongs to the project rather than to any one service — without that, a single
 * facade would fetch the same list five times.
 *
 * How much this buys you depends on how PHP is running. Under PHP-FPM every
 * request is a fresh process, so the cache starts cold each time and a failover
 * costs one extra request to discover regions. That is the failure path, not the
 * happy path, so the cost is small and the alternative — persisting a list of
 * hosts that may receive a bearer token across requests — is not one to reach for
 * lightly. In a long-lived process (a queue worker, a CLI daemon, Swoole,
 * RoadRunner) the cache behaves as it does in Node and is reused for its full TTL.
 *
 * Node also coalesces concurrent discovery fetches behind a single in-flight
 * promise. There is no equivalent here and none is needed: a PHP request issues
 * these sequentially, so there is never a second fetch in flight to join.
 */
final class RegionCache
{
    /** @var array<string, array{origins: list<string>, expiresAt: float}> */
    private array $entries = [];

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    private static ?self $shared = null;

    /** @param (\Closure(): float)|null $clock seconds as a float; defaults to microtime(true) */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** The process-wide instance used unless a caller supplies its own. */
    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    /**
     * The cached origins for $hostKey, or null when absent or expired.
     *
     * @return list<string>|null
     */
    public function get(string $hostKey): ?array
    {
        $entry = $this->entries[$hostKey] ?? null;

        if ($entry === null) {
            return null;
        }

        if (($this->clock)() >= $entry['expiresAt']) {
            unset($this->entries[$hostKey]);

            return null;
        }

        return $entry['origins'];
    }

    /**
     * Stores $origins for $ttlSeconds. A non-positive TTL stores nothing: that is
     * what Cache-Control: max-age=0, or a missing header, is asking for.
     *
     * @param list<string> $origins
     */
    public function put(string $hostKey, array $origins, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            return;
        }

        $this->entries[$hostKey] = [
            'origins' => $origins,
            'expiresAt' => ($this->clock)() + $ttlSeconds,
        ];
    }

    public function clear(): void
    {
        $this->entries = [];
    }
}
