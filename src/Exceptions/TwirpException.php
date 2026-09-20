<?php

declare(strict_types=1);

namespace LiveKit\Exceptions;

/**
 * A Twirp error response.
 *
 * Twirp encodes errors as a JSON object `{code, msg, meta}` regardless of the
 * request content type, so this is parsed with json_decode even when the SDK is
 * speaking binary protobuf.
 *
 * The `code` is passed through verbatim and is deliberately NOT validated
 * against a fixed enum — LiveKit emits codes (such as `malformed`) that some
 * Twirp client libraries do not recognise, and those libraries discard the
 * server's message and metadata when they fail to match.
 */
class TwirpException extends \RuntimeException implements LiveKitException
{
    /**
     * Declared `final` so that `new static()` in `fromResponse()` is provably
     * safe: no subclass (such as SipCallError) can override the constructor
     * with an incompatible signature.
     *
     * @param array<string, string> $meta
     */
    final public function __construct(
        string $message,
        private readonly string $twirpCode,
        private readonly int $httpStatus,
        private readonly array $meta = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromResponse(int $status, string $body): static
    {
        /** @var array{code?: mixed, msg?: mixed, meta?: mixed}|null $decoded */
        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ! isset($decoded['code'])) {
            return new static(
                sprintf('LiveKit returned HTTP %d: %s', $status, trim($body)),
                'unknown',
                $status,
            );
        }

        $meta = [];
        if (isset($decoded['meta']) && is_array($decoded['meta'])) {
            foreach ($decoded['meta'] as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $meta[$key] = (string) $value;
                }
            }
        }

        return new static(
            is_string($decoded['msg'] ?? null) ? $decoded['msg'] : 'LiveKit request failed',
            is_string($decoded['code']) ? $decoded['code'] : 'unknown',
            $status,
            $meta,
        );
    }

    /**
     * The Twirp error code, for example `not_found` or `permission_denied`.
     * Named `getTwirpCode()` rather than `getCode()` because \Throwable::getCode()
     * is declared as returning int.
     */
    public function getTwirpCode(): string
    {
        return $this->twirpCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Twirp error metadata. May contain `error_details`, a base64-encoded
     * protobuf google.rpc.Status, which is preserved rather than decoded.
     *
     * @return array<string, string>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }
}
