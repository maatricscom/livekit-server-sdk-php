<?php

declare(strict_types=1);

namespace LiveKit\Tests\Grants;

use LiveKit\Grants\VideoGrant;
use LiveKit\Tests\Support\TestCase;

final class VideoGrantTest extends TestCase
{
    public function test_omits_every_unset_field(): void
    {
        self::assertSame([], (new VideoGrant())->toArray());
    }

    public function test_emits_plain_bools_only_when_true(): void
    {
        $grant = new VideoGrant(roomCreate: true, roomList: false, roomAdmin: true, room: 'my-room');

        self::assertSame(
            ['roomCreate' => true, 'roomAdmin' => true, 'room' => 'my-room'],
            $grant->toArray()
        );
    }

    /**
     * canPublish and friends are *bool in Go: unset means "the server applies its
     * default" (true for publish/subscribe), while false is an explicit denial.
     * Dropping false would silently grant permission.
     */
    public function test_tri_state_null_omits_the_key(): void
    {
        $grant = new VideoGrant(roomJoin: true, room: 'r', canPublish: null);

        self::assertArrayNotHasKey('canPublish', $grant->toArray());
    }

    public function test_tri_state_false_emits_false(): void
    {
        $grant = new VideoGrant(roomJoin: true, room: 'r', canPublish: false);

        $array = $grant->toArray();

        self::assertArrayHasKey('canPublish', $array);
        self::assertFalse($array['canPublish']);
    }

    public function test_tri_state_true_emits_true(): void
    {
        $array = (new VideoGrant(canSubscribe: true))->toArray();

        self::assertTrue($array['canSubscribe']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function triStateFieldProvider(): array
    {
        return [
            'canPublish' => ['canPublish'],
            'canSubscribe' => ['canSubscribe'],
            'canPublishData' => ['canPublishData'],
            'canUpdateOwnMetadata' => ['canUpdateOwnMetadata'],
            'canSubscribeMetrics' => ['canSubscribeMetrics'],
            'canManageAgentSession' => ['canManageAgentSession'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('triStateFieldProvider')]
    public function test_every_tri_state_field_distinguishes_null_from_false(string $field): void
    {
        $unset = (new VideoGrant(...[$field => null]))->toArray();
        $denied = (new VideoGrant(...[$field => false]))->toArray();

        self::assertArrayNotHasKey($field, $unset, sprintf('%s: null must omit the key', $field));
        self::assertArrayHasKey($field, $denied, sprintf('%s: false must emit the key', $field));
        self::assertFalse($denied[$field], sprintf('%s: false must emit false', $field));
    }

    public function test_can_publish_sources_serializes_as_lowercase_strings(): void
    {
        $grant = new VideoGrant(canPublishSources: ['camera', 'screen_share']);

        self::assertSame(['camera', 'screen_share'], $grant->toArray()['canPublishSources']);
    }

    public function test_json_encodes_without_turning_false_into_omission(): void
    {
        $json = json_encode((new VideoGrant(roomJoin: true, room: 'r', canPublish: false))->toArray());

        self::assertSame('{"roomJoin":true,"room":"r","canPublish":false}', $json);
    }
}
