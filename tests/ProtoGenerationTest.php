<?php

declare(strict_types=1);

namespace LiveKit\Tests;

use LiveKit\Proto\CreateRoomRequest;
use LiveKit\Proto\EgressInfo;
use LiveKit\Proto\IngressInfo;
use LiveKit\Proto\ParticipantInfo;
use LiveKit\Proto\Room;
use LiveKit\Proto\SIPInboundTrunkInfo;
use LiveKit\Proto\WebhookEvent;
use LiveKit\Tests\Support\TestCase;

final class ProtoGenerationTest extends TestCase
{
    /**
     * Constructing a message runs the GPBMetadata initOnce() chain. A generated
     * tree that is missing a transitive import compiles fine but fatals here.
     *
     * @return array<string, array{class-string}>
     */
    public static function messageProvider(): array
    {
        return [
            'Room' => [Room::class],
            'CreateRoomRequest' => [CreateRoomRequest::class],
            'ParticipantInfo' => [ParticipantInfo::class],
            'EgressInfo' => [EgressInfo::class],
            'IngressInfo' => [IngressInfo::class],
            'SIPInboundTrunkInfo' => [SIPInboundTrunkInfo::class],
            'WebhookEvent' => [WebhookEvent::class],
        ];
    }

    /**
     * @param class-string $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('messageProvider')]
    public function test_message_class_can_be_constructed(string $class): void
    {
        $message = new $class();

        self::assertInstanceOf(\Google\Protobuf\Internal\Message::class, $message);
    }

    public function test_message_round_trips_through_binary_serialization(): void
    {
        $request = new CreateRoomRequest();
        $request->setName('my-room');
        $request->setEmptyTimeout(300);
        $request->setMaxParticipants(10);

        $bytes = $request->serializeToString();

        $decoded = new CreateRoomRequest();
        $decoded->mergeFromString($bytes);

        self::assertSame('my-room', $decoded->getName());
        self::assertSame(300, $decoded->getEmptyTimeout());
        self::assertSame(10, $decoded->getMaxParticipants());
    }

    public function test_binary_decoding_preserves_unknown_fields(): void
    {
        // Field 9999, wire type 0 (varint), value 42. No such field exists on Room.
        // Binary protobuf must accept and ignore it; this is why the SDK does not
        // break when LiveKit adds a proto field.
        $room = new Room();
        $room->setName('forward-compat');
        $bytes = $room->serializeToString() . "\xB8\xE0\x04\x2A";

        $decoded = new Room();
        $decoded->mergeFromString($bytes);

        self::assertSame('forward-compat', $decoded->getName());
    }

    public function test_generated_code_uses_the_livekit_proto_namespace(): void
    {
        $reflection = new \ReflectionClass(Room::class);

        self::assertSame('LiveKit\Proto', $reflection->getNamespaceName());
        self::assertFalse(
            class_exists('Livekit\Room', false),
            'Generated code must not squat the global Livekit\ namespace'
        );
    }
}
