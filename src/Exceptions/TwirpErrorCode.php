<?php

declare(strict_types=1);

namespace LiveKit\Exceptions;

/**
 * The error codes the Twirp protocol defines, and the mapping the spec asks a
 * client to apply when a failure did not come from Twirp at all.
 *
 * Constants rather than a PHP enum, deliberately: `TwirpException::getTwirpCode()`
 * returns whatever string the server sent, and a server is free to send a code
 * this list does not know. A backed enum would have to reject that, and losing
 * the code LiveKit actually sent is worse than not being able to match on it.
 * Every other LiveKit SDK settles it the same way — Python's ServerErrorCode,
 * Rust's ServerErrorCode, and TwirPHP's Twirp\ErrorCode are all plain constants.
 *
 * Use them instead of writing the strings out:
 *
 *     if ($e->getTwirpCode() === TwirpErrorCode::NOT_FOUND) { ... }
 *
 * @see https://twitchtv.github.io/twirp/docs/spec_v7.html
 */
final class TwirpErrorCode
{
    public const string CANCELED = 'canceled';

    public const string UNKNOWN = 'unknown';

    public const string INVALID_ARGUMENT = 'invalid_argument';

    public const string MALFORMED = 'malformed';

    public const string DEADLINE_EXCEEDED = 'deadline_exceeded';

    public const string NOT_FOUND = 'not_found';

    public const string BAD_ROUTE = 'bad_route';

    public const string ALREADY_EXISTS = 'already_exists';

    public const string PERMISSION_DENIED = 'permission_denied';

    public const string UNAUTHENTICATED = 'unauthenticated';

    public const string RESOURCE_EXHAUSTED = 'resource_exhausted';

    public const string FAILED_PRECONDITION = 'failed_precondition';

    public const string ABORTED = 'aborted';

    public const string OUT_OF_RANGE = 'out_of_range';

    public const string UNIMPLEMENTED = 'unimplemented';

    public const string INTERNAL = 'internal';

    public const string UNAVAILABLE = 'unavailable';

    public const string DATA_LOSS = 'dataloss';

    /** Metadata key marking an error that a proxy or load balancer produced. */
    public const string META_FROM_INTERMEDIARY = 'http_error_from_intermediary';

    /** Whether $code is one of the codes the Twirp spec defines. */
    public static function isValid(string $code): bool
    {
        return in_array($code, self::all(), true);
    }

    /** @return list<string> */
    public static function all(): array
    {
        /** @var array<string, string> $constants */
        $constants = (new \ReflectionClass(self::class))->getConstants();

        unset($constants['META_FROM_INTERMEDIARY']);

        return array_values($constants);
    }

    /**
     * The code to report for a non-2xx response that is not a Twirp error envelope.
     *
     * A failure reaching a client did not necessarily come from the Twirp server:
     * a load balancer returning "503 Service Temporarily Unavailable" as HTML, a
     * gateway timing out, an auth proxy answering 401. The spec asks clients to
     * best-guess an equivalent code from the HTTP status so a caller can handle
     * those the same way it handles everything else, and this is that table.
     *
     * A redirect counts: Twirp only speaks POST, so a 3xx is always something in
     * the middle rather than the service.
     */
    public static function fromHttpStatus(int $status): string
    {
        if ($status >= 300 && $status <= 399) {
            return self::INTERNAL;
        }

        return match ($status) {
            400 => self::INTERNAL,
            401 => self::UNAUTHENTICATED,
            403 => self::PERMISSION_DENIED,
            404 => self::BAD_ROUTE,
            429 => self::RESOURCE_EXHAUSTED,
            502, 503, 504 => self::UNAVAILABLE,
            default => self::UNKNOWN,
        };
    }
}
