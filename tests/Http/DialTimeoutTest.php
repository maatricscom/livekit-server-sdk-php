<?php

declare(strict_types=1);

namespace LiveKit\Tests\Http;

use LiveKit\Http\DialTimeout;
use LiveKit\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The request timeout for calls that wait on a ringing phone.
 *
 * Shared by SipClient's dialing methods and the Connector's WhatsApp ones, which
 * is why it has its own tests rather than only being exercised through them: a
 * mistake here cuts off a real call at the moment someone answers it.
 */
final class DialTimeoutTest extends TestCase
{
    private const FLOOR = DialTimeout::DEFAULT_RINGING_TIMEOUT_SECONDS + DialTimeout::RINGING_TIMEOUT_MARGIN_SECONDS;

    public function test_the_default_floor_outlasts_the_default_ring_window(): void
    {
        self::assertSame(self::FLOOR, DialTimeout::requestTimeout(null, null));
        self::assertGreaterThan(DialTimeout::DEFAULT_RINGING_TIMEOUT_SECONDS, DialTimeout::requestTimeout(null, null));
    }

    public function test_the_floor_follows_an_explicit_ring_window(): void
    {
        self::assertSame(62, DialTimeout::requestTimeout(null, 60));
        self::assertSame(DialTimeout::RINGING_TIMEOUT_MARGIN_SECONDS, DialTimeout::requestTimeout(null, 0));
    }

    public function test_a_longer_caller_timeout_is_honoured(): void
    {
        self::assertSame(120, DialTimeout::requestTimeout(120, null));
        self::assertSame(120, DialTimeout::requestTimeout(120, 60));
    }

    public function test_a_shorter_caller_timeout_is_raised_to_the_floor(): void
    {
        // Left alone, a 5s timeout aborts the request while the phone is still
        // ringing -- before the callee has had a chance to answer.
        self::assertSame(self::FLOOR, DialTimeout::requestTimeout(5, null));
        self::assertSame(62, DialTimeout::requestTimeout(5, 60));
    }

    /** @return iterable<string, array{int|null, int|null}> */
    public static function negativeInputs(): iterable
    {
        yield 'negative ring window' => [null, -5];
        yield 'negative caller timeout' => [-9, null];
        yield 'both negative' => [-9, -5];
        yield 'negative ring window, positive timeout' => [90, -5];
    }

    #[DataProvider('negativeInputs')]
    public function test_no_input_can_produce_a_non_positive_timeout(?int $timeout, ?int $ringingTimeout): void
    {
        // A negative result becomes a negative X-Twirp-Timeout-Ms, which tells the
        // server something false. A duration cannot be negative, so the floor holds.
        self::assertGreaterThan(0, DialTimeout::requestTimeout($timeout, $ringingTimeout));
    }

    public function test_a_negative_ring_window_is_treated_as_no_wait(): void
    {
        self::assertSame(DialTimeout::RINGING_TIMEOUT_MARGIN_SECONDS, DialTimeout::requestTimeout(null, -5));
    }
}
