<?php

declare(strict_types=1);

namespace LiveKit\Tests\Exceptions;

use LiveKit\Exceptions\LiveKitException;
use LiveKit\Exceptions\SipCallError;
use LiveKit\Exceptions\TwirpException;
use LiveKit\Tests\Support\TestCase;

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

        self::assertSame('unknown', $exception->getTwirpCode());
        self::assertSame(502, $exception->getHttpStatus());
        self::assertStringContainsString('bad gateway', $exception->getMessage());
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
}
