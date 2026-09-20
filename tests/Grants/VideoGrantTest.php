<?php

declare(strict_types=1);

namespace LiveKit\Tests\Grants;

use LiveKit\Exceptions\ConfigurationException;
use LiveKit\Grants\VideoGrant;
use LiveKit\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class VideoGrantTest extends TestCase
{
    public function test_omits_every_unset_field(): void
    {
        self::assertSame([], new VideoGrant()->toArray());
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
        $array = new VideoGrant(canSubscribe: true)->toArray();

        self::assertTrue($array['canSubscribe']);
    }

    /**
     * @return array<string, array{string, \Closure(?bool): VideoGrant}>
     */
    public static function triStateFieldProvider(): array
    {
        return [
            'canPublish' => ['canPublish', static fn (?bool $v): VideoGrant => new VideoGrant(canPublish: $v)],
            'canSubscribe' => ['canSubscribe', static fn (?bool $v): VideoGrant => new VideoGrant(canSubscribe: $v)],
            'canPublishData' => ['canPublishData', static fn (?bool $v): VideoGrant => new VideoGrant(canPublishData: $v)],
            'canUpdateOwnMetadata' => ['canUpdateOwnMetadata', static fn (?bool $v): VideoGrant => new VideoGrant(canUpdateOwnMetadata: $v)],
            'canSubscribeMetrics' => ['canSubscribeMetrics', static fn (?bool $v): VideoGrant => new VideoGrant(canSubscribeMetrics: $v)],
            'canManageAgentSession' => ['canManageAgentSession', static fn (?bool $v): VideoGrant => new VideoGrant(canManageAgentSession: $v)],
        ];
    }

    /**
     * @param \Closure(?bool): VideoGrant $make
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('triStateFieldProvider')]
    public function test_every_tri_state_field_distinguishes_null_from_false(string $field, \Closure $make): void
    {
        $unset = $make(null)->toArray();
        $denied = $make(false)->toArray();
        $allowed = $make(true)->toArray();

        self::assertArrayNotHasKey($field, $unset, sprintf('%s: null must omit the key', $field));
        self::assertArrayHasKey($field, $denied, sprintf('%s: false must emit the key', $field));
        self::assertFalse($denied[$field], sprintf('%s: false must emit false', $field));
        self::assertTrue($allowed[$field], sprintf('%s: true must emit true', $field));
    }

    public function test_can_publish_sources_serializes_as_lowercase_strings(): void
    {
        $grant = new VideoGrant(canPublishSources: ['camera', 'screen_share']);

        self::assertSame(['camera', 'screen_share'], $grant->toArray()['canPublishSources']);
    }

    public function test_json_encodes_without_turning_false_into_omission(): void
    {
        $json = json_encode(new VideoGrant(roomJoin: true, room: 'r', canPublish: false)->toArray());

        self::assertSame('{"roomJoin":true,"room":"r","canPublish":false}', $json);
    }

    // ---------------------------------------------------------------------
    // Track sources
    //
    // The server does TrackSource_value[ToUpper(s)] and falls back to UNKNOWN, so
    // a misspelt source is not an error there -- it is a token that grants nothing
    // and says nothing about why.
    // ---------------------------------------------------------------------

    /** @return iterable<string, array{list<string|int>, list<string>}> */
    public static function acceptedTrackSources(): iterable
    {
        yield 'the documented names' => [['camera', 'screen_share'], ['camera', 'screen_share']];
        yield 'proto enum constants' => [
            [\LiveKit\Proto\TrackSource::CAMERA, \LiveKit\Proto\TrackSource::SCREEN_SHARE_AUDIO],
            ['camera', 'screen_share_audio'],
        ];
        yield 'names in any case' => [['CAMERA', 'Microphone'], ['camera', 'microphone']];
        yield 'the two mixed' => [['camera', \LiveKit\Proto\TrackSource::MICROPHONE], ['camera', 'microphone']];
    }

    /**
     * @param list<string|int> $given
     * @param list<string>     $expected
     */
    #[DataProvider('acceptedTrackSources')]
    public function test_track_sources_are_normalized_to_the_names_livekit_reads(array $given, array $expected): void
    {
        self::assertSame($expected, new VideoGrant(canPublishSources: $given)->toArray()['canPublishSources']);
    }

    /** @return iterable<string, array{string|int}> */
    public static function rejectedTrackSources(): iterable
    {
        yield 'a plausible typo' => ['screenshare'];
        yield 'an invented source' => ['webcam'];
        yield 'an empty string' => [''];
        yield 'UNKNOWN itself' => [\LiveKit\Proto\TrackSource::UNKNOWN];
        yield 'an integer the enum does not define' => [99];
        yield 'a negative integer' => [-1];
    }

    #[DataProvider('rejectedTrackSources')]
    public function test_a_source_livekit_would_read_as_unknown_is_refused(string|int $source): void
    {
        $this->expectException(ConfigurationException::class);

        new VideoGrant(canPublishSources: [$source])->toArray();
    }

    public function test_the_accepted_set_comes_from_the_generated_enum(): void
    {
        // Written out here it would drift from the pinned protocol; derived from the
        // enum it cannot. If LiveKit adds a source, regenerating is all it takes.
        $names = array_keys(new \ReflectionClass(\LiveKit\Proto\TrackSource::class)->getConstants());

        foreach ($names as $name) {
            if ($name === 'UNKNOWN') {
                continue;
            }

            $grant = new VideoGrant(canPublishSources: [strtolower($name)]);
            self::assertSame([strtolower($name)], $grant->toArray()['canPublishSources']);
        }
    }
}
