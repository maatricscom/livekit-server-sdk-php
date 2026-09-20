<?php

declare(strict_types=1);

namespace LiveKit\Tests\Http;

use LiveKit\Http\Failover;
use LiveKit\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The failover policy on its own, without a socket.
 *
 * TwirpClientTest already exercises these through the retry loop; the point of
 * repeating them here is the edge cases that are awkward to reach through a full
 * request -- malformed region URLs, Cache-Control spellings, hosts with ports.
 */
final class FailoverTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function hostnames(): iterable
    {
        yield 'a cloud project' => ['my-project.livekit.cloud', true];
        yield 'a cloud project, uppercased' => ['My-Project.LiveKit.Cloud', true];
        yield 'a deeper subdomain' => ['eu.my-project.livekit.cloud', true];
        yield 'self-hosted' => ['livekit.example.com', false];
        yield 'localhost' => ['127.0.0.1', false];
        // The dotted suffix is what is matched. Everything below ends with the
        // characters "livekit.cloud" while belonging to someone else.
        yield 'a lookalike prefix' => ['evil-livekit.cloud', false];
        yield 'the bare apex' => ['livekit.cloud', false];
        yield 'a suffix hidden in a longer label' => ['notlivekit.cloud', false];
    }

    #[DataProvider('hostnames')]
    public function test_only_livekit_cloud_hosts_are_eligible(string $hostname, bool $expected): void
    {
        self::assertSame($expected, Failover::isCloudHost($hostname));
        self::assertSame($expected ? Failover::MAX_ATTEMPTS : 1, Failover::attempts(true, $hostname));
    }

    #[DataProvider('hostnames')]
    public function test_a_region_pin_redirect_obeys_the_same_domain_rule(string $hostname, bool $expected): void
    {
        // A pin redirect is not gated by the failover switch, but it hands the
        // caller's token to a host named by a server response exactly as failover
        // does, so the set of hosts that may receive it is the same.
        self::assertSame($expected, Failover::allowsRedirect($hostname));
    }

    public function test_force_bypasses_the_domain_rule_for_redirects_too(): void
    {
        self::assertTrue(Failover::allowsRedirect('127.0.0.1', true));
    }

    public function test_disabling_failover_leaves_a_single_attempt(): void
    {
        self::assertSame(1, Failover::attempts(false, 'my-project.livekit.cloud'));
    }

    public function test_force_bypasses_the_cloud_check(): void
    {
        self::assertSame(Failover::MAX_ATTEMPTS, Failover::attempts(true, '127.0.0.1', true));
    }

    public function test_a_timeout_below_the_floor_leaves_a_single_attempt(): void
    {
        $host = 'my-project.livekit.cloud';

        self::assertSame(1, Failover::attempts(true, $host, false, Failover::MIN_TIMEOUT_SECONDS - 1));
        self::assertSame(Failover::MAX_ATTEMPTS, Failover::attempts(true, $host, false, Failover::MIN_TIMEOUT_SECONDS));
        // Zero means "no timeout configured", not "a zero-second timeout".
        self::assertSame(Failover::MAX_ATTEMPTS, Failover::attempts(true, $host, false, 0));
    }

    /** @return iterable<string, array{string, string}> */
    public static function websocketUrls(): iterable
    {
        yield 'wss' => ['wss://x.livekit.cloud', 'https://x.livekit.cloud'];
        yield 'ws' => ['ws://x.livekit.cloud', 'http://x.livekit.cloud'];
        yield 'https is left alone' => ['https://x.livekit.cloud', 'https://x.livekit.cloud'];
        yield 'a host containing ws is not rewritten' => ['https://ws.livekit.cloud', 'https://ws.livekit.cloud'];
    }

    #[DataProvider('websocketUrls')]
    public function test_region_urls_are_normalized_to_http(string $input, string $expected): void
    {
        self::assertSame($expected, Failover::toHttp($input));
    }

    public function test_an_origin_drops_the_path_but_keeps_the_port(): void
    {
        self::assertSame('https://x.livekit.cloud', Failover::origin('https://x.livekit.cloud/twirp/foo'));
        self::assertSame('http://127.0.0.1:10000', Failover::origin('http://127.0.0.1:10000'));
        self::assertSame('https://x.livekit.cloud', Failover::origin('wss://x.livekit.cloud'));
        self::assertNull(Failover::origin('not a url'));
    }

    public function test_host_keys_distinguish_ports_and_ignore_case(): void
    {
        self::assertSame('x.livekit.cloud', Failover::hostKey('https://X.LiveKit.Cloud/path'));
        self::assertSame('127.0.0.1:9999', Failover::hostKey('http://127.0.0.1:9999'));
        self::assertNotSame(Failover::hostKey('http://127.0.0.1:9999'), Failover::hostKey('http://127.0.0.1:10000'));
    }

    public function test_a_default_port_does_not_make_a_host_look_like_a_different_one(): void
    {
        // A region list may spell the primary with its default port while the client
        // was configured without it. Keying those separately would spend a failover
        // attempt re-asking the host that just failed.
        self::assertSame(
            Failover::hostKey('https://x.livekit.cloud'),
            Failover::hostKey('https://x.livekit.cloud:443')
        );
        self::assertSame(
            Failover::hostKey('http://x.livekit.cloud'),
            Failover::hostKey('http://x.livekit.cloud:80')
        );

        // A non-default port is still meaningful, and http/https do not share one.
        self::assertNotSame(
            Failover::hostKey('https://x.livekit.cloud'),
            Failover::hostKey('https://x.livekit.cloud:8443')
        );
        self::assertNotSame(
            Failover::hostKey('https://x.livekit.cloud:80'),
            Failover::hostKey('http://x.livekit.cloud:80')
        );
    }

    public function test_pick_next_refuses_a_region_outside_livekit_cloud(): void
    {
        // The region list is a server response, and the next request carries the
        // caller's bearer token. Checking only the configured host would make the
        // domain guarantee hold for the first request and nothing after it.
        self::assertNull(Failover::pickNext(['https://attacker.example.com'], []));

        self::assertSame(
            'https://good.livekit.cloud',
            Failover::pickNext(['https://attacker.example.com', 'https://good.livekit.cloud'], [])
        );

        // ...and the lookalike is no more acceptable here than it is as a host.
        self::assertNull(Failover::pickNext(['https://evil-livekit.cloud'], []));
    }

    public function test_force_lets_the_suite_point_at_a_local_mock(): void
    {
        self::assertSame('http://127.0.0.1:10000', Failover::pickNext(['http://127.0.0.1:10000'], [], true));
    }

    public function test_pick_next_skips_hosts_already_attempted(): void
    {
        $regions = ['https://a.livekit.cloud', 'https://b.livekit.cloud', 'https://c.livekit.cloud'];

        self::assertSame(
            'https://b.livekit.cloud',
            Failover::pickNext($regions, ['a.livekit.cloud' => true])
        );

        self::assertNull(Failover::pickNext($regions, [
            'a.livekit.cloud' => true,
            'b.livekit.cloud' => true,
            'c.livekit.cloud' => true,
        ]));
    }

    public function test_pick_next_skips_a_malformed_entry_rather_than_failing(): void
    {
        // The list is a server response. One unusable URL in it should cost us that
        // region, not the whole retry.
        self::assertSame(
            'https://good.livekit.cloud',
            Failover::pickNext(['://nonsense', 'https://good.livekit.cloud'], [])
        );
    }

    /** @return iterable<string, array{string|null, int}> */
    public static function cacheControlHeaders(): iterable
    {
        yield 'absent' => [null, 0];
        yield 'empty' => ['', 0];
        yield 'a plain max-age' => ['max-age=300', 300];
        yield 'max-age among other directives' => ['public, max-age=120, must-revalidate', 120];
        yield 'uppercase' => ['MAX-AGE=45', 45];
        yield 'zero means do not cache' => ['max-age=0', 0];
        yield 'no-store without max-age' => ['no-store', 0];
        yield 'unparseable' => ['max-age=soon', 0];
        // s-maxage is for shared proxies, not for this client; honouring it would
        // cache the region list for a lifetime that was never meant for us.
        yield 's-maxage is ignored' => ['s-maxage=600', 0];
        // Saturating to PHP_INT_MAX would put the expiry so far out that the entry
        // never refreshes again.
        yield 'absurd max-age is capped' => ['max-age=99999999999999999999', Failover::MAX_REGION_TTL_SECONDS];
        yield 'a day and a half is capped' => ['max-age=129600', Failover::MAX_REGION_TTL_SECONDS];
        yield 'just under the cap is kept' => ['max-age=86399', 86399];
    }

    #[DataProvider('cacheControlHeaders')]
    public function test_only_max_age_sets_the_cache_lifetime(?string $header, int $expected): void
    {
        self::assertSame($expected, Failover::parseMaxAge($header));
    }

    public function test_the_backoff_grows_exponentially(): void
    {
        self::assertSame(200_000, Failover::backoffMicroseconds(0, 200));
        self::assertSame(400_000, Failover::backoffMicroseconds(1, 200));
        self::assertSame(800_000, Failover::backoffMicroseconds(2, 200));
        self::assertSame(0, Failover::backoffMicroseconds(3, 0));
    }
}
