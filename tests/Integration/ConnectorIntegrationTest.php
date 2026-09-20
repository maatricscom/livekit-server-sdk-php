<?php

declare(strict_types=1);

namespace LiveKit\Tests\Integration;

use LiveKit\Exceptions\TwirpErrorCode;
use LiveKit\Exceptions\TwirpException;
use LiveKit\Options\CreateRoomOptions;
use LiveKit\Proto\ConnectTwilioCallRequest\TwilioCallDirection;
use LiveKit\Proto\DisconnectWhatsAppCallRequest\DisconnectReason;
use LiveKit\Proto\SessionDescription;
use LiveKit\Tests\Integration\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The connector bridges real telephony, so this suite is arranged so that it
 * cannot place a call -- not by relying on Meta to refuse one, but by never
 * reaching Meta at all.
 *
 * LiveKit validates these requests in a fixed order, measured against a live
 * deployment: the SDP type first, then the Cloud API version, and only then is
 * anything forwarded to Meta. Each WhatsApp test here fails at one of the first
 * two gates, so the credential in the request is never used and no call can be
 * attempted. The difference matters -- a test that sent a plausible request with
 * a deliberately wrong API key would be one upstream change away from placing a
 * real call, and there is no such thing as unsending one.
 *
 * Everything that is a real value here is fictional anyway: the number is inside
 * +1 555 0100-0199, reserved so it can never reach a subscriber, and the phone
 * number id and API key are strings no account could match.
 *
 * connectTwilioCall() is the exception and runs for real, because it places no
 * call: it provisions the websocket endpoint that Twilio's Media Stream would
 * later connect to, and hands back the URL. Note that it leaves a transient
 * `wactr_` room behind, which this test deletes rather than waiting out.
 */
final class ConnectorIntegrationTest extends IntegrationTestCase
{
    /** Reserved for fictional use, so it can never reach a subscriber. */
    private const string FICTIONAL_NUMBER = '+15550100';

    private const string UNUSABLE_PHONE_NUMBER_ID = 'not-a-real-phone-number-id';

    /** Never used: every WhatsApp test below is refused before this is read. */
    private const string UNUSABLE_API_KEY = 'not-a-real-meta-api-key';

    /**
     * The `v` prefix is the trap: Meta's Graph path is /v23.0/{id}/calls, but this
     * field wants "23.0". LiveKit refuses the prefixed form, which is what makes it
     * a safe way to reach the dial route without dialling -- and this package's own
     * example shipped with it until a live deployment said otherwise.
     */
    private const string REFUSED_API_VERSION = 'v23.0';

    public function test_dialling_is_refused_before_the_credential_is_ever_used(): void
    {
        try {
            $this->skipIfUnavailable(
                fn () => $this->noFailoverClient()->connector->dialWhatsAppCall(
                    self::UNUSABLE_PHONE_NUMBER_ID,
                    self::FICTIONAL_NUMBER,
                    self::UNUSABLE_API_KEY,
                    self::REFUSED_API_VERSION,
                ),
                'The WhatsApp connector',
            );
        } catch (TwirpException $e) {
            self::assertSame(TwirpErrorCode::INVALID_ARGUMENT, $e->getTwirpCode(), $e->getMessage());
            self::assertStringContainsString('version', $e->getMessage(), 'the version gate is what refused it, so nothing was forwarded to Meta');

            return;
        }

        self::fail('a dial with an unsupported API version should not have succeeded');
    }

    /**
     * An inbound call arrives with an offer. Sending an answer is refused at the
     * first gate of all, before the API version and long before Meta.
     */
    public function test_accepting_with_the_wrong_sdp_type_is_refused_first(): void
    {
        $sdp = new SessionDescription();
        $sdp->setType('answer');
        $sdp->setSdp("v=0\r\no=- 0 0 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\n");

        try {
            $this->skipIfUnavailable(
                fn () => $this->noFailoverClient()->connector->acceptWhatsAppCall(
                    self::UNUSABLE_PHONE_NUMBER_ID,
                    self::UNUSABLE_API_KEY,
                    self::REFUSED_API_VERSION,
                    'wacid-that-does-not-exist',
                    $sdp,
                ),
                'The WhatsApp connector',
            );
        } catch (TwirpException $e) {
            self::assertSame(TwirpErrorCode::INVALID_ARGUMENT, $e->getTwirpCode(), $e->getMessage());
            self::assertStringContainsString('sdp type', $e->getMessage(), 'the sdp type is checked before anything else');

            return;
        }

        self::fail('accepting a call with an answer instead of an offer should not have succeeded');
    }

    /** @return iterable<string, array{callable(self): mixed}> */
    public static function callsAgainstAnAbsentCall(): iterable
    {
        yield 'connectWhatsAppCall' => [static function (self $test): mixed {
            $sdp = new SessionDescription();
            $sdp->setType('answer');
            $sdp->setSdp("v=0\r\no=- 0 0 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\n");

            return $test->noFailoverClient()->connector->connectWhatsAppCall('wacid-that-does-not-exist', $sdp);
        }];

        yield 'disconnectWhatsAppCall' => [static fn (self $test): mixed => $test->noFailoverClient()->connector->disconnectWhatsAppCall(
            'wacid-that-does-not-exist',
            self::UNUSABLE_API_KEY,
            DisconnectReason::USER_INITIATED,
        )];
    }

    /** @param callable(self): mixed $call */
    #[DataProvider('callsAgainstAnAbsentCall')]
    public function test_a_call_that_does_not_exist_is_reported_as_not_found(callable $call): void
    {
        try {
            $this->skipIfUnavailable(fn (): mixed => $call($this), 'The WhatsApp connector');
        } catch (TwirpException $e) {
            self::assertSame(TwirpErrorCode::NOT_FOUND, $e->getTwirpCode(), $e->getMessage());

            return;
        }

        self::fail('an rpc against a call that does not exist should not have succeeded');
    }

    /** @return iterable<string, array{int}> */
    public static function twilioDirections(): iterable
    {
        yield 'inbound' => [TwilioCallDirection::TWILIO_CALL_DIRECTION_INBOUND];
        yield 'outbound' => [TwilioCallDirection::TWILIO_CALL_DIRECTION_OUTBOUND];
    }

    #[DataProvider('twilioDirections')]
    public function test_twilio_connect_hands_back_a_media_stream_url(int $direction): void
    {
        $room = $this->scratchName('twilio');
        $before = $this->roomNames();

        $this->cleanUpAfter(
            body: function () use ($direction, $room): void {
                $this->livekit->room->createRoom(new CreateRoomOptions(name: $room, emptyTimeout: 30));

                $response = $this->skipIfUnavailable(
                    fn () => $this->livekit->connector->connectTwilioCall($direction, $room),
                    'The Twilio connector',
                );

                $url = $response->getConnectUrl();

                self::assertNotSame('', $url, 'the server hands back somewhere for Twilio to stream to');
                self::assertSame('wss', parse_url($url, PHP_URL_SCHEME), 'a media stream needs a websocket url');
            },
            cleanup: function () use ($room, $before): void {
                $this->livekit->room->deleteRoom($room);

                // The call provisions a transient room of its own, which would
                // otherwise sit here until its own timeout ran out.
                foreach (array_diff($this->roomNames(), $before, [$room]) as $left) {
                    try {
                        $this->livekit->room->deleteRoom($left);
                    } catch (TwirpException) {
                        // Already closed itself; nothing to do.
                    }
                }
            },
            describe: sprintf('room "%s"', $room),
        );
    }

    /** @return list<string> */
    private function roomNames(): array
    {
        return array_map(
            static fn (\LiveKit\Proto\Room $room): string => $room->getName(),
            $this->livekit->room->listRooms()
        );
    }
}
