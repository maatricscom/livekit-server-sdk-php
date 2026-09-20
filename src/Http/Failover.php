<?php

declare(strict_types=1);

namespace LiveKit\Http;

/**
 * The decisions behind region failover, kept apart from the code that performs it.
 *
 * On a retryable failure — any transport error, or an HTTP 5xx — the client
 * discovers alternative LiveKit Cloud regions via /settings/regions and replays
 * the request against the next one. Everything here is a pure function of its
 * arguments so the policy can be tested without a socket; TwirpClient holds the
 * loop and RegionCache holds the state.
 *
 * Failover deliberately engages only for LiveKit Cloud hosts. A retry sends the
 * caller's bearer token to an origin this SDK learned at runtime, from a server
 * response — so the set of hosts that can ever receive it stays pinned to a
 * domain suffix LiveKit controls, rather than to whatever a response said.
 */
final class Failover
{
    /**
     * Total attempts for an eligible host: the original request plus two
     * fallbacks. Fixed rather than configurable, so retries cannot be tuned into
     * something that would overwhelm the server.
     */
    public const MAX_ATTEMPTS = 3;

    /** Base for the exponential backoff between attempts, in milliseconds. */
    public const BACKOFF_BASE_MS = 200;

    /**
     * Below this per-request timeout a retry is unlikely to complete, and many
     * clients would retry in lockstep across regions. A short request gets one
     * attempt instead — a thundering-herd guard.
     */
    public const MIN_TIMEOUT_SECONDS = 5;

    /** The only domain suffix whose hosts may receive a replayed request. */
    public const CLOUD_SUFFIX = '.livekit.cloud';

    /**
     * How many attempts a request to $hostname gets; 1 means no failover.
     *
     * @param bool $force bypasses the cloud-host check. Test-only: it is what lets
     *                    the suite exercise failover against a local mock, and it
     *                    disables the guard described in the class docblock.
     */
    public static function attempts(bool $enabled, string $hostname, bool $force = false, int $timeoutSeconds = 0): int
    {
        if (!$enabled || !($force || self::isCloudHost($hostname))) {
            return 1;
        }

        if ($timeoutSeconds > 0 && $timeoutSeconds < self::MIN_TIMEOUT_SECONDS) {
            return 1;
        }

        return self::MAX_ATTEMPTS;
    }

    /**
     * Whether $hostname is a LiveKit Cloud project domain.
     *
     * Matched on the dotted suffix, never with a bare str_contains or a
     * "ends with livekit.cloud" test: "evil-livekit.cloud" ends with the string
     * but is a different registrable domain, and matching it would hand the
     * bearer token to an unrelated host.
     */
    public static function isCloudHost(string $hostname): bool
    {
        return str_ends_with(strtolower($hostname), self::CLOUD_SUFFIX);
    }

    /**
     * A stable key for a host, including its port, used to avoid retrying an
     * origin that has already been attempted.
     */
    public static function hostKey(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'])) {
            return strtolower(trim($url));
        }

        $key = strtolower($parts['host']);

        return isset($parts['port']) ? $key . ':' . $parts['port'] : $key;
    }

    /**
     * The scheme://host[:port] of $url, dropping any path — the region list gives
     * full URLs, but a replay only borrows their origin.
     */
    public static function origin(string $url): ?string
    {
        $parts = parse_url(self::toHttp($url));

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Normalizes a region URL to an http(s) scheme. /settings/regions advertises
     * the same ws:// and wss:// URLs that client SDKs connect with; the HTTP API
     * lives on the same origin.
     */
    public static function toHttp(string $url): string
    {
        if (str_starts_with($url, 'wss://')) {
            return 'https://' . substr($url, 6);
        }

        if (str_starts_with($url, 'ws://')) {
            return 'http://' . substr($url, 5);
        }

        return $url;
    }

    /**
     * The first region origin whose host has not been attempted yet, or null when
     * they all have. Malformed entries are skipped rather than thrown on: the list
     * comes from a server response, and one bad URL should not fail the retry.
     *
     * @param list<string>         $regionUrls
     * @param array<string, true>  $attempted keyed by hostKey()
     */
    public static function pickNext(array $regionUrls, array $attempted): ?string
    {
        foreach ($regionUrls as $url) {
            $origin = self::origin($url);

            if ($origin === null) {
                continue;
            }

            if (!isset($attempted[self::hostKey($origin)])) {
                return $origin;
            }
        }

        return null;
    }

    /**
     * The max-age of a Cache-Control header, in seconds; 0 when absent,
     * non-positive or unparseable, which means "do not cache".
     *
     * Only max-age is honoured. s-maxage targets shared proxies, not this client,
     * so treating it as our TTL would cache a region list for the wrong lifetime.
     */
    public static function parseMaxAge(?string $cacheControl): int
    {
        if ($cacheControl === null || trim($cacheControl) === '') {
            return 0;
        }

        foreach (explode(',', $cacheControl) as $directive) {
            $directive = strtolower(trim($directive));

            if (!str_starts_with($directive, 'max-age=')) {
                continue;
            }

            $value = substr($directive, strlen('max-age='));

            if (preg_match('/^\d+$/', $value) !== 1) {
                return 0;
            }

            $seconds = (int) $value;

            return $seconds > 0 ? $seconds : 0;
        }

        return 0;
    }

    /** The backoff before attempt number $attempt (zero-based), in microseconds. */
    public static function backoffMicroseconds(int $attempt, int $baseMs = self::BACKOFF_BASE_MS): int
    {
        return $baseMs * (2 ** $attempt) * 1000;
    }
}
