<?php

declare(strict_types=1);

namespace LiveKit\Tests\Services;

use Google\Protobuf\Internal\Message;
use LiveKit\Options\CreateRoomOptions;
use LiveKit\Options\ListRoomsOptions;
use LiveKit\Proto\CreateRoomRequest;
use LiveKit\Proto\DeleteRoomRequest;
use LiveKit\Proto\DeleteRoomResponse;
use LiveKit\Proto\ListRoomsRequest;
use LiveKit\Proto\ListRoomsResponse;
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

    public function testListRoomsFiltersByNameAndUnwrapsTheResponse(): void
    {
        $client = $this->client(
            (new ListRoomsResponse())->setRooms([
                (new Room())->setSid('RM_1')->setName('alpha'),
                (new Room())->setSid('RM_2')->setName('beta'),
            ]),
        );

        $rooms = $client->listRooms(new ListRoomsOptions(names: ['alpha', 'beta']));

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'RoomService', 'ListRooms');

        $sent = $this->decodeRequest(ListRoomsRequest::class);
        self::assertSame(['alpha', 'beta'], iterator_to_array($sent->getNames()));

        $this->assertVideoGrant(['roomList' => true], $request);

        self::assertCount(2, $rooms);
        self::assertContainsOnlyInstancesOf(Room::class, $rooms);
        self::assertSame('alpha', $rooms[0]->getName());
        self::assertSame('beta', $rooms[1]->getName());
    }

    public function testListRoomsWithoutOptionsSendsAnEmptyNameFilter(): void
    {
        $client = $this->client(new ListRoomsResponse());

        $rooms = $client->listRooms();

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'RoomService', 'ListRooms');

        $sent = $this->decodeRequest(ListRoomsRequest::class);
        self::assertCount(0, $sent->getNames());

        $this->assertVideoGrant(['roomList' => true], $request);

        self::assertSame([], $rooms);
    }

    public function testDeleteRoomPostsDeleteRoomRequest(): void
    {
        $client = $this->client(new DeleteRoomResponse());

        $client->deleteRoom('my-room');

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'RoomService', 'DeleteRoom');

        $sent = $this->decodeRequest(DeleteRoomRequest::class);
        self::assertSame('my-room', $sent->getRoom());

        $this->assertVideoGrant(['roomCreate' => true], $request);
    }

    /**
     * deleteRoom is COUNTER-INTUITIVE: it requires roomCreate, not roomAdmin, and it carries no
     * `room` claim. Verified against LiveKit's official Node SDK (livekit-server-sdk v2.19.0,
     * RoomServiceClient.deleteRoom -> { roomCreate: true }). Sending roomAdmin here produces a 401
     * against a strict deployment, so this assertion must not be "fixed" to match the other methods.
     */
    public function testDeleteRoomUsesRoomCreateGrantNotRoomAdmin(): void
    {
        $client = $this->client(new DeleteRoomResponse());

        $client->deleteRoom('my-room');

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'RoomService', 'DeleteRoom');

        $sent = $this->decodeRequest(DeleteRoomRequest::class);
        self::assertSame('my-room', $sent->getRoom());

        $video = $this->claims($request)['video'];
        self::assertIsArray($video);
        self::assertTrue($video['roomCreate']);
        self::assertArrayNotHasKey('roomAdmin', $video);
        self::assertArrayNotHasKey('room', $video);
        self::assertCount(1, $video);
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
