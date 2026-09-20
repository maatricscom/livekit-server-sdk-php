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

    /**
     * The generated tree calls helpers on the protobuf runtime, and which helpers
     * exist depends on the runtime's version. This is what the `google/protobuf`
     * floor and the `ext-protobuf` conflict in composer.json are for -- protoc 36
     * emits `GPBUtil::compatibleInt64()`, which arrived in 5.34.0, so on anything
     * older the generated getter for an `optional` int64 field raises "Call to
     * undefined method" the first time it is read.
     *
     * Scanned rather than listed: a protoc bump that introduces a dependency on
     * some other helper is then caught here instead of at a caller's call site.
     */
    public function test_every_runtime_helper_the_generated_code_calls_exists(): void
    {
        $called = [];

        foreach (self::generatedFiles() as $file) {
            preg_match_all('/GPBUtil::([a-zA-Z0-9_]+)\(/', (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $method) {
                $called[$method] = true;
            }
        }

        self::assertNotEmpty($called, 'Found no GPBUtil calls at all — the scan is broken, not the tree.');

        $missing = array_values(array_filter(
            array_keys($called),
            static fn (string $m): bool => ! method_exists(\Google\Protobuf\Internal\GPBUtil::class, $m)
        ));

        self::assertSame([], $missing, sprintf(
            'The generated code calls %s on a protobuf runtime that does not have it. '
            . 'Raise the floor in composer.json, or regenerate with an older protoc.',
            implode(', ', array_map(static fn (string $m): string => "GPBUtil::{$m}()", $missing))
        ));
    }

    /**
     * The check above is static. This one reads the fields that actually depend on
     * the newest helper, because a method that exists but misbehaves would pass it.
     */
    public function test_the_getters_that_need_the_newest_runtime_helper_work(): void
    {
        // Every generated `optional` int64 getter returns its default through
        // GPBUtil::compatibleInt64(). These are the messages that have one.
        //
        // assertEquals, not assertSame: compatibleInt64() is exactly the helper that
        // returns an int where the platform's integers are wide enough and a numeric
        // string where they are not, so the type is not ours to pin. Reading the
        // value at all is the assertion -- on a runtime without the helper, every
        // one of these raises before returning anything.
        self::assertEquals(0, (new \LiveKit\Proto\EventMetric())->getEndTimestampMs());
        self::assertEquals(0, (new \LiveKit\Proto\ChatMessage())->getEditTimestamp());
        self::assertEquals(0, (new \LiveKit\Proto\DataStream\Header())->getTotalLength());
    }

    /**
     * @return iterable<string>
     */
    private static function generatedFiles(): iterable
    {
        $root = dirname(__DIR__) . '/src/Proto';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
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
