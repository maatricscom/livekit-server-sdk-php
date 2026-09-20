<?php

declare(strict_types=1);

namespace LiveKit\Tests\Services;

use Google\Protobuf\Internal\Message;
use LiveKit\Options\CreateRoomOptions;
use LiveKit\Proto\CreateRoomRequest;
use LiveKit\Proto\Room;
use LiveKit\Services\RoomServiceClient;
use LiveKit\Tests\Support\TwirpTestCase;

final class RoomServiceClientTest extends TwirpTestCase
{
    public function testCreateRoomPostsCreateRoomRequestWithRoomCreateGrant(): void
    {
        $psr17 = $this->psr17();

        $expected = (new Room())
            ->setSid('RM_abc')
            ->setName('my-room')
            ->setEmptyTimeout(300);

        $this->http->pushResponse($this->protoResponse($expected));

        $client = new RoomServiceClient(
            self::HOST,
            self::API_KEY,
            self::API_SECRET,
            null,
            $this->http,
            $psr17,
            $psr17,
        );

        $room = $client->createRoom(new CreateRoomOptions(
            name: 'my-room',
            emptyTimeout: 300,
            departureTimeout: 120,
            maxParticipants: 20,
            metadata: '{"owner":"yasin"}',
            tags: ['team' => 'core'],
            minPlayoutDelay: 100,
            maxPlayoutDelay: 900,
            syncStreams: true,
            replayEnabled: false,
        ));

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'RoomService', 'CreateRoom');

        $sent = $this->decodeRequest(CreateRoomRequest::class);

        self::assertSame('my-room', $sent->getName());
        self::assertSame(300, $sent->getEmptyTimeout());
        self::assertSame(120, $sent->getDepartureTimeout());
        self::assertSame(20, $sent->getMaxParticipants());
        self::assertSame('{"owner":"yasin"}', $sent->getMetadata());
        self::assertSame('core', $sent->getTags()['team']);
        self::assertSame(100, $sent->getMinPlayoutDelay());
        self::assertSame(900, $sent->getMaxPlayoutDelay());
        self::assertTrue($sent->getSyncStreams());
        self::assertFalse($sent->getReplayEnabled());
        self::assertSame('', $sent->getRoomPreset());

        $claims = $this->claims($request);
        self::assertSame(self::API_KEY, $claims['iss']);
        self::assertIsArray($claims['video']);
        self::assertTrue($claims['video']['roomCreate']);
        self::assertCount(1, $claims['video']);

        self::assertSame('RM_abc', $room->getSid());
        self::assertSame('my-room', $room->getName());
    }

    private function client(Message $response): RoomServiceClient
    {
        $psr17 = $this->psr17();
        $this->http->pushResponse($this->protoResponse($response));

        return new RoomServiceClient(
            self::HOST,
            self::API_KEY,
            self::API_SECRET,
            null,
            $this->http,
            $psr17,
            $psr17,
        );
    }
}
