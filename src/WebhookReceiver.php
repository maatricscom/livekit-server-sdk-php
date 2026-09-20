<?php

declare(strict_types=1);

namespace LiveKit;

use LiveKit\Exceptions\WebhookVerificationException;
use LiveKit\Proto\WebhookEvent;

/**
 * Verifies and parses inbound LiveKit webhooks.
 *
 * LiveKit POSTs the protojson-encoded event with:
 *   Authorization: <raw JWT>            (note: no "Bearer " prefix)
 *   content-type:  application/webhook+json
 *
 * The token carries a `sha256` claim holding the base64 (standard alphabet,
 * padded) SHA-256 digest of the exact body bytes. Verification therefore
 * requires the RAW body — a body that has been decoded and re-encoded will not
 * match, because Go's protojson output is not byte-reproducible.
 */
final class WebhookReceiver
{
    /**
     * LiveKit uses a custom media type deliberately, so that frameworks do not
     * silently parse the body before the signature has been checked.
     */
    public const string CONTENT_TYPE = 'application/webhook+json';

    private readonly TokenVerifier $verifier;

    public function __construct(?string $apiKey = null, ?string $apiSecret = null)
    {
        $this->verifier = new TokenVerifier($apiKey, $apiSecret);
    }

    /**
     * @param string      $rawBody    The unmodified request body bytes
     * @param string|null $authHeader The Authorization header value
     * @param bool        $skipAuth   Bypasses verification. Development only.
     */
    public function receive(
        string $rawBody,
        ?string $authHeader,
        bool $skipAuth = false,
        int $clockToleranceSeconds = 60,
    ): WebhookEvent {
        if (! $skipAuth) {
            $this->verify($rawBody, $authHeader, $clockToleranceSeconds);
        }

        // The two protobuf runtimes disagree about what is acceptable here, in
        // opposite directions: the pure-PHP parser takes a JSON array and hands back
        // a default message, while ext-protobuf takes an empty body and does the
        // same. Whichever one is installed would otherwise decide what this SDK
        // does with a malformed webhook, so the check belongs here. Every LiveKit
        // event is a JSON object.
        if (! str_starts_with(ltrim($rawBody), '{')) {
            throw WebhookVerificationException::bodyIsNotAJsonObject();
        }

        $event = new WebhookEvent();

        try {
            // ignore_unknown = true: LiveKit adds webhook fields over time and the
            // strict parser would throw GPBDecodeException on the first new one.
            $event->mergeFromJsonString($rawBody, true);
        } catch (\Throwable $e) {
            // That exception is the protobuf runtime's, not ours. Reachable with a
            // truncated body, with skipAuth in development, and with anything that
            // is not protojson at all.
            throw WebhookVerificationException::unparseableBody($e);
        }

        return $event;
    }

    private function verify(string $rawBody, ?string $authHeader, int $clockToleranceSeconds): void
    {
        if ($authHeader === null || trim($authHeader) === '') {
            throw WebhookVerificationException::missingAuthorizationHeader();
        }

        // LiveKit sends the bare token; tolerate a Bearer prefix without requiring it.
        $token = preg_replace('/^Bearer\s+/i', '', trim($authHeader)) ?? '';

        try {
            $claims = $this->verifier->verify($token, $clockToleranceSeconds);
        } catch (\Throwable $e) {
            throw WebhookVerificationException::invalidToken($e);
        }

        $expected = $claims['sha256'] ?? null;

        if (! is_string($expected) || $expected === '') {
            throw WebhookVerificationException::missingSha256Claim();
        }

        $actual = base64_encode(hash('sha256', $rawBody, true));

        if (! hash_equals($expected, $actual)) {
            throw WebhookVerificationException::checksumMismatch();
        }
    }
}
