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

    /**
     * Real protojson bytes for a WebhookEvent{event: room_started, id: EV_abc123,
     * createdAt: 1789891388, room: {sid: RM_xyz, name: my-room}}, produced by
     * livekit/protocol's own utils/protojson.Marshal() -- the same call
     * webhook/url_notifier.go makes before signing -- via
     * bin/generate-webhook-fixture.go. Not a hand-written approximation.
     */
    private function goFixtureBody(): string
    {
        $body = file_get_contents(__DIR__ . '/Fixtures/webhook-event.json');
        self::assertIsString($body, 'Regenerate with bin/generate-webhook-fixture.go');

        return $body;
    }

    public function test_verifies_and_parses_a_well_formed_webhook(): void
    {
        [$body, $auth] = $this->signedWebhook($this->goFixtureBody());

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
     * Verification is byte-exact, not semantic-JSON-exact: the raw body must be
     * hashed as received, never a JSON structure reconstructed from it. This
     * test pins that failure mode against real Go protojson bytes (see
     * goFixtureBody()) so nobody "helpfully" adds a decode/re-encode round-trip
     * before hashing.
     *
     * Finding while writing this against the real fixture: for this event's
     * shape (no forward slashes, no non-ASCII, no floats), a *compact* PHP
     * json_decode()/json_encode() round-trip reproduces the Go bytes exactly --
     * Go's protojson field order follows proto field numbers, and PHP preserves
     * key order through decode/encode, so with nothing PHP escapes differently
     * there is nothing left to diverge on. That would make a compact round-trip
     * a vacuous precondition here. A pretty-printed re-encode is used instead:
     * it is still exactly the "decode then re-encode" mistake this test exists
     * to catch (plenty of frameworks and debug middleware pretty-print JSON by
     * default), and its divergence from Go's compact output does not depend on
     * the payload happening to contain characters PHP escapes differently.
     */
    public function test_rejects_a_reserialized_body(): void
    {
        $body = $this->goFixtureBody();
        [, $auth] = $this->signedWebhook($body);

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $reserialized = json_encode($decoded, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
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
