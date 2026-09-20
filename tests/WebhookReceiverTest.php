<?php

declare(strict_types=1);

namespace LiveKit\Tests;

use LiveKit\AccessToken;
use LiveKit\Enums\WebhookEventType;
use LiveKit\Exceptions\WebhookVerificationException;
use LiveKit\Proto\WebhookEvent;
use LiveKit\Tests\Support\TestCase;
use LiveKit\WebhookReceiver;

final class WebhookReceiverTest extends TestCase
{
    /**
     * Builds a webhook exactly as LiveKit's Go url_notifier does: protojson body,
     * base64-std sha256 of those exact bytes, carried in the token's sha256 claim.
     *
     * @return array{0: string, 1: string} raw body, Authorization header value
     */
    private function signedWebhook(string $body): array
    {
        $token = new AccessToken(self::API_KEY, self::API_SECRET);
        $token->setTtl(300)->setSha256(base64_encode(hash('sha256', $body, true)));

        return [$body, $token->toJwt()];
    }

    private function sampleBody(): string
    {
        // protojson output: camelCase keys, int64 as a string, zero values omitted.
        return '{"event":"room_started","id":"EV_abc123","createdAt":"1789891388","room":{"sid":"RM_xyz","name":"my-room"}}';
    }

    public function test_verifies_and_parses_a_well_formed_webhook(): void
    {
        [$body, $auth] = $this->signedWebhook($this->sampleBody());

        $event = (new WebhookReceiver(self::API_KEY, self::API_SECRET))->receive($body, $auth);

        self::assertInstanceOf(WebhookEvent::class, $event);
        self::assertSame('room_started', $event->getEvent());
        self::assertSame('EV_abc123', $event->getId());
        self::assertSame('my-room', $event->getRoom()?->getName());
    }

    /**
     * LiveKit sends the raw JWT in Authorization with no Bearer prefix. We accept
     * a stray prefix for robustness but must never require it.
     */
    public function test_accepts_a_bearer_prefix_even_though_livekit_does_not_send_one(): void
    {
        [$body, $auth] = $this->signedWebhook($this->sampleBody());

        $event = (new WebhookReceiver(self::API_KEY, self::API_SECRET))->receive($body, 'Bearer ' . $auth);

        self::assertSame('room_started', $event->getEvent());
    }

    public function test_rejects_a_tampered_body(): void
    {
        [, $auth] = $this->signedWebhook($this->sampleBody());

        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessageMatches('/raw request body/');

        (new WebhookReceiver(self::API_KEY, self::API_SECRET))
            ->receive('{"event":"room_finished"}', $auth);
    }

    /**
     * Go's protojson output is not byte-reproducible, so hashing a re-encoded
     * body fails verification. This test pins that failure mode so nobody
     * "helpfully" adds a json_encode() round-trip later.
     *
     * Uses a body containing an unescaped "/" rather than sampleBody(): PHP's
     * json_encode() escapes forward slashes to "\/" by default, which is
     * enough to change the bytes on round-trip. sampleBody() is plain ASCII
     * with no slashes, so decoding and re-encoding it is a no-op in PHP and
     * would not actually exercise this failure mode.
     */
    public function test_rejects_a_reserialized_body(): void
    {
        $body = '{"event":"room_started","id":"EV_abc123","room":{"sid":"RM_xyz","name":"my-room","metadata":"a/b"}}';
        [, $auth] = $this->signedWebhook($body);

        $reserialized = json_encode(json_decode($body, true, 512, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR);
        self::assertNotSame($body, $reserialized, 'Precondition: re-encoding must change the bytes');

        $this->expectException(WebhookVerificationException::class);

        (new WebhookReceiver(self::API_KEY, self::API_SECRET))->receive($reserialized, $auth);
    }

    public function test_rejects_a_token_signed_with_another_secret(): void
    {
        $body = $this->sampleBody();

        $token = new AccessToken(self::API_KEY, 'a-completely-different-secret-value');
        $token->setSha256(base64_encode(hash('sha256', $body, true)));

        $this->expectException(WebhookVerificationException::class);

        (new WebhookReceiver(self::API_KEY, self::API_SECRET))->receive($body, $token->toJwt());
    }

    public function test_rejects_a_missing_authorization_header(): void
    {
        $this->expectException(WebhookVerificationException::class);

        (new WebhookReceiver(self::API_KEY, self::API_SECRET))->receive($this->sampleBody(), null);
    }

    public function test_rejects_a_token_without_a_sha256_claim(): void
    {
        $token = new AccessToken(self::API_KEY, self::API_SECRET);

        $this->expectException(WebhookVerificationException::class);

        (new WebhookReceiver(self::API_KEY, self::API_SECRET))->receive($this->sampleBody(), $token->toJwt());
    }

    public function test_skip_auth_bypasses_verification_for_local_development(): void
    {
        $event = (new WebhookReceiver(self::API_KEY, self::API_SECRET))
            ->receive($this->sampleBody(), null, skipAuth: true);

        self::assertSame('room_started', $event->getEvent());
    }

    /**
     * LiveKit adds webhook fields over time; the parser must tolerate them.
     */
    public function test_ignores_unknown_fields_in_the_payload(): void
    {
        $body = '{"event":"room_started","id":"EV_1","brandNewFieldFromANewerServer":42}';
        [, $auth] = $this->signedWebhook($body);

        $event = (new WebhookReceiver(self::API_KEY, self::API_SECRET))->receive($body, $auth);

        self::assertSame('room_started', $event->getEvent());
    }

    /**
     * Webhook tokens live 5 minutes. An expired one must be rejected, and the
     * failure must surface as an SDK exception rather than a raw JWT one.
     */
    public function test_rejects_an_expired_webhook_token(): void
    {
        $body = $this->sampleBody();

        $now = time();
        $jwt = \Firebase\JWT\JWT::encode(
            [
                'iss' => self::API_KEY,
                'iat' => $now - 3600,
                'nbf' => $now - 3600,
                'exp' => $now - 1800,
                'sha256' => base64_encode(hash('sha256', $body, true)),
            ],
            self::API_SECRET,
            'HS256'
        );

        $this->expectException(WebhookVerificationException::class);

        (new WebhookReceiver(self::API_KEY, self::API_SECRET))->receive($body, $jwt);
    }

    public function test_accepts_a_token_inside_the_clock_tolerance_window(): void
    {
        $body = $this->sampleBody();

        $now = time();
        $jwt = \Firebase\JWT\JWT::encode(
            [
                'iss' => self::API_KEY,
                'iat' => $now,
                // 30 seconds in the future: rejected with no tolerance, accepted with 60s
                'nbf' => $now + 30,
                'exp' => $now + 300,
                'sha256' => base64_encode(hash('sha256', $body, true)),
            ],
            self::API_SECRET,
            'HS256'
        );

        $event = (new WebhookReceiver(self::API_KEY, self::API_SECRET))
            ->receive($body, $jwt, clockToleranceSeconds: 60);

        self::assertSame('room_started', $event->getEvent());
    }

    public function test_exposes_the_event_name_constants(): void
    {
        self::assertSame('room_started', WebhookEventType::RoomStarted->value);
        self::assertSame('participant_joined', WebhookEventType::ParticipantJoined->value);
        self::assertSame('egress_ended', WebhookEventType::EgressEnded->value);
        self::assertSame('application/webhook+json', WebhookReceiver::CONTENT_TYPE);
    }

    public function test_event_type_can_be_resolved_from_a_received_event(): void
    {
        [$body, $auth] = $this->signedWebhook($this->sampleBody());

        $event = (new WebhookReceiver(self::API_KEY, self::API_SECRET))->receive($body, $auth);

        self::assertSame(WebhookEventType::RoomStarted, WebhookEventType::tryFrom($event->getEvent()));
    }
}
