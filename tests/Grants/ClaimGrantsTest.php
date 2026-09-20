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

    public function test_omits_grant_objects_that_serialize_to_nothing(): void
    {
        $grants = (new ClaimGrants())->setVideo(new VideoGrant())->setSip(new SIPGrant());

        self::assertSame([], $grants->toArray());
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
}
