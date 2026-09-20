<?php

declare(strict_types=1);

namespace LiveKit\Tests;

use LiveKit\AccessToken;
use LiveKit\Enums\WebhookEventType;
use LiveKit\Exceptions\LiveKitException;
use LiveKit\Exceptions\WebhookVerificationException;
use LiveKit\Proto\WebhookEvent;
use LiveKit\Tests\Support\TestCase;
use LiveKit\WebhookReceiver;
use PHPUnit\Framework\Attributes\DataProvider;

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
     * createdAt: 1789891388, room: {sid: RM_xyz, name: "oda-ü", metadata: a URL}},
     * produced by livekit/protocol's own utils/protojson.Marshal() -- the same
     * call webhook/url_notifier.go makes before signing -- via
     * bin/generate-webhook-fixture.go. Not a hand-written approximation.
     *
     * The room name and metadata deliberately carry a non-ASCII character and a
     * URL: both are ordinary content for a real LiveKit webhook (room names and
     * metadata are arbitrary application-supplied strings), and both round-trip
     * through Go's protojson unchanged while PHP's json_encode() re-escapes them
     * by default. See test_rejects_a_reserialized_body().
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

        $room = $event->getRoom();
        self::assertNotNull($room);
        self::assertSame('oda-ü', $room->getName());
        self::assertSame('https://example.com/rooms/my-room', $room->getMetadata());
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
     * test pins the realistic version of that mistake -- a plain
     * json_encode(json_decode($raw, true)) round-trip, the kind of "helpful"
     * normalization someone adds without thinking -- against real Go protojson
     * bytes (see goFixtureBody()).
     *
     * Earlier version of this test: a first attempt used a *plain-ASCII*
     * fixture, and a compact round-trip of it reproduced the Go bytes exactly.
     * That was real (Go's protojson field order follows proto field numbers,
     * PHP preserves key order through decode/encode, and with nothing
     * escaping-sensitive in the content there was nothing left to diverge on)
     * but it meant the precondition below was vacuous, and a regression here
     * would have gone undetected. It is exactly why "hash the raw bytes" is the
     * rule rather than "a compact re-encode is usually safe": whether a given
     * payload happens to survive re-encoding depends on its content. The
     * fixture now carries a non-ASCII room name and a URL in its metadata --
     * ordinary content for a real webhook -- which PHP's json_encode()
     * re-escapes (\uXXXX, \/) by default, so the round-trip now diverges for a
     * substantive, content-driven reason rather than by accident.
     */
    public function test_rejects_a_reserialized_body(): void
    {
        $body = $this->goFixtureBody();
        [, $auth] = $this->signedWebhook($body);

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $reserialized = json_encode($decoded, JSON_THROW_ON_ERROR);
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

    /** @return iterable<string, array{string}> */
    public static function bodiesThatCannotBeDecoded(): iterable
    {
        yield 'truncated json' => ['{"event":'];
        yield 'not json at all' => ['not json at all'];
        yield 'empty' => [''];
    }

    /**
     * The protobuf runtime raises GPBDecodeException, which is not ours. It is
     * reachable with a truncated body, with skipAuth in development, and with
     * anything that is not protojson -- so it has to be wrapped like any other
     * failure this package reports.
     */
    #[DataProvider('bodiesThatCannotBeDecoded')]
    public function test_a_body_that_cannot_be_decoded_is_a_livekit_exception(string $body): void
    {
        $caught = null;

        try {
            (new WebhookReceiver(self::API_KEY, self::API_SECRET))->receive($body, null, skipAuth: true);
        } catch (\Throwable $e) {
            // Assigned rather than asserted in place: self::fail() raises an
            // AssertionFailedError, which this same catch would swallow.
            $caught = $e;
        }

        self::assertInstanceOf(LiveKitException::class, $caught);
        self::assertInstanceOf(WebhookVerificationException::class, $caught);
        self::assertNotNull($caught->getPrevious(), 'The protobuf error is kept as the cause.');
    }

    public function test_a_json_array_decodes_to_an_empty_event_rather_than_failing(): void
    {
        // Recorded because it is surprising: protojson accepts a JSON array where an
        // object belongs and yields a default message. Harmless — LiveKit never
        // sends one, and the signature check is not what is being skipped here —
        // but worth pinning so a future change to it is noticed.
        $event = (new WebhookReceiver(self::API_KEY, self::API_SECRET))->receive('[1,2,3]', null, skipAuth: true);

        self::assertSame('', $event->getEvent());
    }

    public function test_the_signature_is_checked_before_the_body_is_decoded(): void
    {
        // Order matters: decoding first would run the parser over bytes nobody has
        // vouched for yet. An unsigned request must fail on the token, not the body.
        try {
            (new WebhookReceiver(self::API_KEY, self::API_SECRET))->receive('{"event":', null);
            self::fail('Expected the request to be rejected');
        } catch (WebhookVerificationException $e) {
            self::assertStringContainsString('Authorization header', $e->getMessage());
        }
    }
}
