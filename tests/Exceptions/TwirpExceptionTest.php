<?php

declare(strict_types=1);

namespace LiveKit\Tests\Exceptions;

use LiveKit\Exceptions\LiveKitException;
use LiveKit\Exceptions\SipCallError;
use LiveKit\Exceptions\TwirpErrorCode;
use LiveKit\Exceptions\TwirpException;
use LiveKit\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class TwirpExceptionTest extends TestCase
{
    public function test_parses_a_standard_twirp_error_body(): void
    {
        $exception = TwirpException::fromResponse(
            404,
            '{"code":"not_found","msg":"room does not exist"}'
        );

        self::assertInstanceOf(LiveKitException::class, $exception);
        self::assertSame('not_found', $exception->getTwirpCode());
        self::assertSame('room does not exist', $exception->getMessage());
        self::assertSame(404, $exception->getHttpStatus());
        self::assertSame([], $exception->getMeta());
    }

    public function test_preserves_meta_including_error_details(): void
    {
        $exception = TwirpException::fromResponse(
            400,
            '{"code":"invalid_argument","msg":"bad","meta":{"error_details":"CgVoZWxsbw==","argument":"name"}}'
        );

        self::assertSame(
            ['error_details' => 'CgVoZWxsbw==', 'argument' => 'name'],
            $exception->getMeta()
        );
    }

    /**
     * TwirPHP's ErrorCode::isValid() lacks `malformed` and rewrites such errors
     * into a generic `internal`, discarding msg and meta. We pass codes through
     * verbatim precisely to avoid that.
     */
    public function test_passes_unknown_error_codes_through_verbatim(): void
    {
        $exception = TwirpException::fromResponse(
            400,
            '{"code":"malformed","msg":"could not decode request","meta":{"cause":"eof"}}'
        );

        self::assertSame('malformed', $exception->getTwirpCode());
        self::assertSame('could not decode request', $exception->getMessage());
        self::assertSame(['cause' => 'eof'], $exception->getMeta());
    }

    public function test_handles_a_non_json_error_body(): void
    {
        $exception = TwirpException::fromResponse(502, '<html>bad gateway</html>');

        // Not a Twirp envelope, so it did not come from the Twirp handler. The spec
        // asks a client to guess an equivalent code from the status rather than
        // report every such failure as "unknown".
        self::assertSame(TwirpErrorCode::UNAVAILABLE, $exception->getTwirpCode());
        self::assertSame(502, $exception->getHttpStatus());
        self::assertStringContainsString('bad gateway', $exception->getMessage());
    }

    /** @return iterable<string, array{int, string}> */
    public static function intermediaryStatuses(): iterable
    {
        yield '301 moved' => [301, TwirpErrorCode::INTERNAL];
        yield '302 found' => [302, TwirpErrorCode::INTERNAL];
        yield '400 bad request' => [400, TwirpErrorCode::INTERNAL];
        yield '401 unauthorized' => [401, TwirpErrorCode::UNAUTHENTICATED];
        yield '403 forbidden' => [403, TwirpErrorCode::PERMISSION_DENIED];
        yield '404 not found' => [404, TwirpErrorCode::BAD_ROUTE];
        yield '429 too many requests' => [429, TwirpErrorCode::RESOURCE_EXHAUSTED];
        yield '502 bad gateway' => [502, TwirpErrorCode::UNAVAILABLE];
        yield '503 unavailable' => [503, TwirpErrorCode::UNAVAILABLE];
        yield '504 gateway timeout' => [504, TwirpErrorCode::UNAVAILABLE];
        yield '500, which has no better guess' => [500, TwirpErrorCode::UNKNOWN];
        yield '418, which has none either' => [418, TwirpErrorCode::UNKNOWN];
    }

    /**
     * The table in the Twirp spec, for responses a proxy or load balancer produced
     * rather than the service. Without it every one of these is "unknown", and a
     * caller cannot tell a rate limit from a gateway timeout.
     */
    #[DataProvider('intermediaryStatuses')]
    public function test_a_non_twirp_response_is_mapped_by_its_status(int $status, string $expected): void
    {
        self::assertSame($expected, TwirpException::fromResponse($status, 'not an envelope')->getTwirpCode());
    }

    public function test_an_intermediary_error_says_so_in_its_metadata(): void
    {
        $exception = TwirpException::fromResponse(503, '<html>Service Unavailable</html>');

        // This is how a caller tells "LiveKit said no" from "something between us
        // said no", which is the difference between a bug and an outage.
        self::assertSame('true', $exception->getMeta()[TwirpErrorCode::META_FROM_INTERMEDIARY] ?? null);
        self::assertSame('503', $exception->getMeta()['status_code'] ?? null);
        self::assertStringContainsString('Service Unavailable', $exception->getMeta()['body'] ?? '');
    }

    public function test_a_redirect_reports_where_it_pointed_rather_than_its_body(): void
    {
        // Twirp only speaks POST, so a redirect is always an intermediary. Where it
        // pointed is the useful part; the body of a 302 rarely says anything.
        $exception = TwirpException::fromResponse(302, '', 'https://elsewhere.example.com/');

        self::assertSame(TwirpErrorCode::INTERNAL, $exception->getTwirpCode());
        self::assertSame('https://elsewhere.example.com/', $exception->getMeta()['location'] ?? null);
        self::assertArrayNotHasKey('body', $exception->getMeta());
        self::assertStringContainsString('elsewhere.example.com', $exception->getMessage());
    }

    public function test_a_real_twirp_envelope_is_not_marked_as_intermediary(): void
    {
        $exception = TwirpException::fromResponse(503, '{"code":"unavailable","msg":"restarting"}');

        self::assertSame(TwirpErrorCode::UNAVAILABLE, $exception->getTwirpCode());
        self::assertSame('restarting', $exception->getMessage());
        self::assertArrayNotHasKey(TwirpErrorCode::META_FROM_INTERMEDIARY, $exception->getMeta());
    }

    public function test_the_code_vocabulary_matches_the_spec(): void
    {
        self::assertCount(18, TwirpErrorCode::all());
        self::assertTrue(TwirpErrorCode::isValid('not_found'));
        self::assertFalse(TwirpErrorCode::isValid('notfound'));

        // A server is free to send a code this list does not know, and passing it
        // through beats replacing it with something we invented.
        self::assertSame('brand_new_code', TwirpException::fromResponse(500, '{"code":"brand_new_code"}')->getTwirpCode());
    }

    /**
     * A `{"code":...}` body with no `msg` must not lose the HTTP status the way the
     * no-`code` branch above already preserves it -- both fall back to a message
     * that still names the status, rather than a bare, context-free string.
     */
    public function test_a_missing_message_still_carries_the_http_status(): void
    {
        $exception = TwirpException::fromResponse(503, '{"code":"unavailable"}');

        self::assertSame('unavailable', $exception->getTwirpCode());
        self::assertSame(503, $exception->getHttpStatus());
        self::assertStringContainsString('503', $exception->getMessage());
    }

    public function test_builds_a_sip_call_error_when_meta_carries_sip_status(): void
    {
        $exception = SipCallError::fromResponse(
            500,
            '{"code":"internal","msg":"call failed","meta":{"sip_status_code":"486","sip_status":"Busy Here"}}'
        );

        self::assertInstanceOf(SipCallError::class, $exception);
        self::assertSame(486, $exception->getSipStatusCode());
        self::assertSame('Busy Here', $exception->getSipStatus());
    }

    public function test_sip_call_error_without_sip_meta_returns_nulls(): void
    {
        $exception = SipCallError::fromResponse(500, '{"code":"internal","msg":"boom"}');

        self::assertNull($exception->getSipStatusCode());
        self::assertNull($exception->getSipStatus());
    }

    /**
     * getMeta() values are always strings; a non-numeric sip_status_code must
     * come back as null (absent), never silently coerced to 0 by (int) casting.
     */
    public function test_sip_call_error_with_a_non_numeric_status_code_returns_null(): void
    {
        $exception = SipCallError::fromResponse(
            500,
            '{"code":"internal","msg":"call failed","meta":{"sip_status_code":"not-a-number","sip_status":"Unknown"}}'
        );

        self::assertNull($exception->getSipStatusCode());
        self::assertSame('Unknown', $exception->getSipStatus());
    }

    public function test_an_unparseable_body_is_shown_but_not_at_any_length(): void
    {
        $e = TwirpException::fromResponse(502, str_repeat('A', 100_000));

        // The body usually explains the failure, so it is worth showing -- but the
        // other end chooses its length, and this message ends up in logs.
        self::assertLessThan(2_000, strlen($e->getMessage()));
        self::assertStringContainsString('100000 bytes total', $e->getMessage());
        self::assertSame(TwirpErrorCode::UNAVAILABLE, $e->getTwirpCode());
        self::assertSame(502, $e->getHttpStatus());
    }

    public function test_a_short_unparseable_body_is_shown_whole(): void
    {
        $e = TwirpException::fromResponse(502, "  <html>Bad Gateway</html>\n");

        self::assertStringContainsString('<html>Bad Gateway</html>', $e->getMessage());
        self::assertStringNotContainsString('bytes total', $e->getMessage());
    }
}
