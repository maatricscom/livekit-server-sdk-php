<?php

declare(strict_types=1);

namespace LiveKit\Tests\MockServer;

use LiveKit\ClientOptions;
use LiveKit\Enums\WireFormat;
use LiveKit\Options\CreateIngressOptions;
use LiveKit\Options\CreateRoomOptions;
use LiveKit\Options\CreateSipParticipantOptions;
use LiveKit\Options\EncodedOutputs;
use LiveKit\Proto\EncodedFileOutput;
use LiveKit\Tests\MockServer\Support\MockServerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Proves the server actually parsed the bytes we sent, in both wire formats.
 *
 * This is the distinction that matters, and the reason RpcSweepTest alone is not
 * enough: the mock decodes a request with `_ = proto.Unmarshal(body, req)` and
 * *discards the error*, so a malformed body still produces a 200. Appending a
 * junk byte to every protobuf request passes the sweep unnoticed.
 *
 * What cannot be faked is the echo. The mock copies same-named scalar fields
 * from the decoded request onto the response, so a value that comes back is a
 * value that survived: our encoder -> the wire -> the server's decoder -> the
 * server's encoder -> our decoder. Asserting on echoed values is therefore the
 * only check here that fails when our request encoding is wrong.
 *
 * Both integer and boolean fields are asserted, not just strings, because
 * varint-encoded scalars are where a hand-rolled encoder would go wrong and a
 * string-only test would not notice.
 *
 * Verified by mutation: prepending a junk byte to the protobuf body, and sending
 * protojson bytes under the protobuf content type, each fail four tests here.
 * One mutation it does NOT catch is worth knowing about -- a junk byte *appended*
 * after an otherwise valid message still passes, because Go fills the fields as
 * it parses and only then hits the bad byte, and the mock throws the error away.
 * So this proves our encoder produces a message the server reads correctly; it
 * does not prove the server would reject a body we corrupted at the end.
 */
final class EchoRoundTripTest extends MockServerTestCase
{
    /** @return iterable<string, array{WireFormat}> */
    public static function wireFormats(): iterable
    {
        yield 'binary protobuf' => [WireFormat::Protobuf];
        yield 'json' => [WireFormat::Json];
    }

    #[DataProvider('wireFormats')]
    public function test_room_fields_survive_the_round_trip(WireFormat $format): void
    {
        $room = $this->apiFor($format)->room->createRoom(new CreateRoomOptions(
            name: 'echo-room',
            emptyTimeout: 1234,
            maxParticipants: 7,
            metadata: 'echo-metadata',
        ));

        self::assertSame('echo-room', $room->getName());
        self::assertSame('echo-metadata', $room->getMetadata());
        self::assertSame(1234, $room->getEmptyTimeout(), 'uint32 did not survive the round trip');
        self::assertSame(7, $room->getMaxParticipants(), 'uint32 did not survive the round trip');
    }

    #[DataProvider('wireFormats')]
    public function test_egress_fields_survive_the_round_trip(WireFormat $format): void
    {
        $info = $this->apiFor($format)->egress->startRoomCompositeEgress(
            'echo-egress-room',
            new EncodedOutputs(file: (new EncodedFileOutput())->setFilepath('out.mp4')),
        );

        self::assertSame('echo-egress-room', $info->getRoomName());
    }

    #[DataProvider('wireFormats')]
    public function test_ingress_fields_survive_the_round_trip(WireFormat $format): void
    {
        $info = $this->apiFor($format)->ingress->createIngress(new CreateIngressOptions(
            name: 'echo-ingress',
            roomName: 'echo-ingress-room',
            participantIdentity: 'echo-identity',
            participantName: 'Echo',
            enableTranscoding: true,
        ));

        self::assertSame('echo-ingress', $info->getName());
        self::assertSame('echo-ingress-room', $info->getRoomName());
        self::assertSame('echo-identity', $info->getParticipantIdentity());
        self::assertSame('Echo', $info->getParticipantName());
        self::assertTrue($info->getEnableTranscoding(), 'bool did not survive the round trip');
    }

    #[DataProvider('wireFormats')]
    public function test_sip_participant_fields_survive_the_round_trip(WireFormat $format): void
    {
        $info = $this->apiFor($format)->sip->createSipParticipant(
            'ST_echo',
            '+15551234567',
            'echo-sip-room',
            new CreateSipParticipantOptions(participantIdentity: 'echo-caller'),
        );

        self::assertSame('echo-sip-room', $info->getRoomName());
        self::assertSame('echo-caller', $info->getParticipantIdentity());
    }

    // AgentDispatchService has no echo test on purpose. The mock registers
    // response population for RoomService, Egress, Ingress, SIP and Connector
    // only; AgentDispatchService falls through to the empty-message default, so
    // there is nothing to echo. Its permission table entry does exist, so
    // RpcSweepTest still covers those three RPCs' grants -- but their request
    // encoding is only covered by the unit tests.

    private function apiFor(WireFormat $format): \LiveKit\LiveKitAPI
    {
        return $this->api(['delayMs' => 0], new ClientOptions(wireFormat: $format));
    }
}
