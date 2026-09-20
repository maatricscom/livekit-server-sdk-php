<?php

declare(strict_types=1);

namespace LiveKit\Tests\Grants;

use LiveKit\Grants\ClaimGrants;
use LiveKit\Grants\SIPGrant;
use LiveKit\Grants\VideoGrant;
use LiveKit\Tests\Support\TestCase;

final class ClaimGrantsTest extends TestCase
{
    public function test_is_empty_by_default(): void
    {
        self::assertSame([], (new ClaimGrants())->toArray());
    }

    /**
     * Go embeds ClaimGrants into tokenClaims alongside jwt.RegisteredClaims, so
     * every grant key sits at the top level of the payload. There is no `grants`
     * wrapper object.
     */
    public function test_produces_a_flat_payload(): void
    {
        $grants = (new ClaimGrants())
            ->setIdentity('alice')
            ->setName('Alice')
            ->setVideo(new VideoGrant(roomJoin: true, room: 'my-room'));

        self::assertSame(
            [
                'identity' => 'alice',
                'name' => 'Alice',
                'video' => ['roomJoin' => true, 'room' => 'my-room'],
            ],
            $grants->toArray()
        );
    }

    public function test_omits_a_grant_that_was_never_set(): void
    {
        self::assertArrayNotHasKey('video', (new ClaimGrants())->toArray());
    }

    /**
     * Go's `json:"video,omitempty"` is on a *pointer*, which tests nil rather than
     * emptiness: a grant that was explicitly set still serializes, as `{}` when it
     * carries no permissions. json_encode([]) would emit a JSON array (`[]`), which
     * Go cannot unmarshal into *VideoGrant, so an empty grant must become an object.
     */
    public function test_a_grant_set_but_carrying_no_permissions_serializes_as_an_empty_object(): void
    {
        $grants = (new ClaimGrants())->setVideo(new VideoGrant())->setSip(new SIPGrant());

        $claims = $grants->toArray();

        self::assertArrayHasKey('video', $claims);
        self::assertArrayHasKey('sip', $claims);
        self::assertEquals(new \stdClass(), $claims['video']);
        self::assertEquals(new \stdClass(), $claims['sip']);

        self::assertSame('{"video":{},"sip":{}}', json_encode($claims));
    }

    public function test_includes_sip_grant_when_set(): void
    {
        $grants = (new ClaimGrants())->setSip(new SIPGrant(call: true));

        self::assertSame(['sip' => ['call' => true]], $grants->toArray());
    }

    public function test_carries_participant_metadata_and_kind(): void
    {
        $grants = (new ClaimGrants())
            ->setKind('agent')
            ->setKindDetails(['worker'])
            ->setMetadata('{"tier":"pro"}')
            ->setSha256('Zm9vYmFy')
            ->setRoomPreset('default');

        self::assertSame(
            [
                'kind' => 'agent',
                'kindDetails' => ['worker'],
                'sha256' => 'Zm9vYmFy',
                'metadata' => '{"tier":"pro"}',
                'roomPreset' => 'default',
            ],
            $grants->toArray()
        );
    }

    /**
     * PHP's json_encode([]) produces `[]`, but the server expects `attributes`
     * to be a JSON object. Go omits it entirely when empty, so we do too.
     */
    public function test_omits_empty_attributes_rather_than_encoding_an_array(): void
    {
        $grants = (new ClaimGrants())->setAttributes([]);

        self::assertArrayNotHasKey('attributes', $grants->toArray());
    }

    public function test_encodes_non_empty_attributes_as_an_object(): void
    {
        $grants = (new ClaimGrants())->setAttributes(['seat' => '3A']);

        self::assertSame('{"attributes":{"seat":"3A"}}', json_encode($grants->toArray()));
    }

    public function test_a_room_configuration_is_serialized_the_way_the_server_reads_it(): void
    {
        $config = (new \LiveKit\Proto\RoomConfiguration())
            ->setName('my-room')
            ->setEmptyTimeout(300)
            ->setMetadata('{"tier":"gold"}');

        $claims = (new ClaimGrants())->setRoomConfig($config)->toArray();

        // Go marshals this field with protojson, not encoding/json. That means
        // camelCase field names from the proto's json_name, which a hand-built
        // array would get wrong for anything multi-word.
        self::assertSame(
            ['name' => 'my-room', 'emptyTimeout' => 300, 'metadata' => '{"tier":"gold"}'],
            $claims['roomConfig']
        );
    }

    public function test_a_room_configuration_set_but_empty_stays_an_object(): void
    {
        $claims = (new ClaimGrants())->setRoomConfig(new \LiveKit\Proto\RoomConfiguration())->toArray();

        // Same reason as the grants: Go's omitempty on a pointer tests nil, so a
        // config that was set serializes even when it carries nothing -- and it has
        // to be {} rather than [], which is what json_encode makes of an empty array.
        self::assertInstanceOf(\stdClass::class, $claims['roomConfig']);
        self::assertSame('{"roomConfig":{}}', json_encode($claims, JSON_THROW_ON_ERROR));
    }

    public function test_no_room_configuration_means_no_claim(): void
    {
        self::assertArrayNotHasKey('roomConfig', (new ClaimGrants())->toArray());
    }
}
