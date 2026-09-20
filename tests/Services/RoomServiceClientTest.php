<?php

declare(strict_types=1);

namespace LiveKit\Tests\Services;

use Google\Protobuf\Internal\Message;
use LiveKit\Options\CreateRoomOptions;
use LiveKit\Options\ListRoomsOptions;
use LiveKit\Options\SendDataOptions;
use LiveKit\Options\UpdateParticipantOptions;
use LiveKit\Proto\CreateRoomRequest;
use LiveKit\Proto\DataPacket\Kind;
use LiveKit\Proto\DeleteRoomRequest;
use LiveKit\Proto\DeleteRoomResponse;
use LiveKit\Proto\ListParticipantsRequest;
use LiveKit\Proto\ListParticipantsResponse;
use LiveKit\Proto\ListRoomsRequest;
use LiveKit\Proto\ListRoomsResponse;
use LiveKit\Proto\MuteRoomTrackRequest;
use LiveKit\Proto\MuteRoomTrackResponse;
use LiveKit\Proto\ParticipantInfo;
use LiveKit\Proto\ParticipantPermission;
use LiveKit\Proto\RemoveParticipantResponse;
use LiveKit\Proto\Room;
use LiveKit\Proto\RoomParticipantIdentity;
use LiveKit\Proto\SendDataRequest;
use LiveKit\Proto\SendDataResponse;
use LiveKit\Proto\TrackInfo;
use LiveKit\Proto\TrackSource;
use LiveKit\Proto\UpdateParticipantRequest;
use LiveKit\Proto\UpdateSubscriptionsRequest;
use LiveKit\Proto\UpdateSubscriptionsResponse;
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

    public function testListParticipantsUnwrapsTheResponse(): void
    {
        $client = $this->client(
            (new ListParticipantsResponse())->setParticipants([
                (new ParticipantInfo())->setSid('PA_1')->setIdentity('alice'),
                (new ParticipantInfo())->setSid('PA_2')->setIdentity('bob'),
            ]),
        );

        $participants = $client->listParticipants('my-room');

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'RoomService', 'ListParticipants');

        $sent = $this->decodeRequest(ListParticipantsRequest::class);
        self::assertSame('my-room', $sent->getRoom());

        $this->assertVideoGrant(['roomAdmin' => true, 'room' => 'my-room'], $request);

        self::assertCount(2, $participants);
        self::assertContainsOnlyInstancesOf(ParticipantInfo::class, $participants);
        self::assertSame('alice', $participants[0]->getIdentity());
        self::assertSame('bob', $participants[1]->getIdentity());
    }

    public function testGetParticipantPostsRoomParticipantIdentity(): void
    {
        $client = $this->client(
            (new ParticipantInfo())->setSid('PA_1')->setIdentity('alice')->setName('Alice'),
        );

        $participant = $client->getParticipant('my-room', 'alice');

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'RoomService', 'GetParticipant');

        $sent = $this->decodeRequest(RoomParticipantIdentity::class);
        self::assertSame('my-room', $sent->getRoom());
        self::assertSame('alice', $sent->getIdentity());

        $this->assertVideoGrant(['roomAdmin' => true, 'room' => 'my-room'], $request);

        self::assertSame('Alice', $participant->getName());
    }

    public function testRemoveParticipantPostsRoomParticipantIdentity(): void
    {
        $client = $this->client(new RemoveParticipantResponse());

        $client->removeParticipant('my-room', 'alice');

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'RoomService', 'RemoveParticipant');

        $sent = $this->decodeRequest(RoomParticipantIdentity::class);
        self::assertSame('my-room', $sent->getRoom());
        self::assertSame('alice', $sent->getIdentity());
        self::assertSame(0, $sent->getRevokeTokenTs());

        $this->assertVideoGrant(['roomAdmin' => true, 'room' => 'my-room'], $request);
    }

    public function testMutePublishedTrackSendsTheMutedFlag(): void
    {
        $client = $this->client(
            (new MuteRoomTrackResponse())->setTrack(
                (new TrackInfo())->setSid('TR_1')->setMuted(true),
            ),
        );

        $result = $client->mutePublishedTrack('my-room', 'alice', 'TR_1', true);

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'RoomService', 'MutePublishedTrack');

        $sent = $this->decodeRequest(MuteRoomTrackRequest::class);
        self::assertSame('my-room', $sent->getRoom());
        self::assertSame('alice', $sent->getIdentity());
        self::assertSame('TR_1', $sent->getTrackSid());
        self::assertTrue($sent->getMuted());

        $this->assertVideoGrant(['roomAdmin' => true, 'room' => 'my-room'], $request);

        $track = $result->getTrack();
        self::assertInstanceOf(TrackInfo::class, $track);
        self::assertTrue($track->getMuted());
    }

    public function testMutePublishedTrackCanUnmute(): void
    {
        $client = $this->client(
            (new MuteRoomTrackResponse())->setTrack((new TrackInfo())->setSid('TR_1')->setMuted(false)),
        );

        $client->mutePublishedTrack('my-room', 'alice', 'TR_1', false);

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'RoomService', 'MutePublishedTrack');

        $sent = $this->decodeRequest(MuteRoomTrackRequest::class);
        self::assertSame('my-room', $sent->getRoom());
        self::assertSame('alice', $sent->getIdentity());
        self::assertSame('TR_1', $sent->getTrackSid());
        self::assertFalse($sent->getMuted());

        $this->assertVideoGrant(['roomAdmin' => true, 'room' => 'my-room'], $request);
    }

    public function testUpdateParticipantMapsMetadataNameAttributesAndPermission(): void
    {
        $client = $this->client((new ParticipantInfo())->setIdentity('alice')->setName('Alice B'));

        $permission = (new ParticipantPermission())
            ->setCanSubscribe(true)
            ->setCanPublish(false)
            ->setCanPublishData(true)
            ->setCanPublishSources([TrackSource::MICROPHONE]);

        $participant = $client->updateParticipant('my-room', 'alice', new UpdateParticipantOptions(
            metadata: '{"role":"host"}',
            permission: $permission,
            name: 'Alice B',
            attributes: ['seat' => '3', 'stale' => ''],
        ));

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'RoomService', 'UpdateParticipant');

        $sent = $this->decodeRequest(UpdateParticipantRequest::class);
        self::assertSame('my-room', $sent->getRoom());
        self::assertSame('alice', $sent->getIdentity());
        self::assertSame('{"role":"host"}', $sent->getMetadata());
        self::assertSame('Alice B', $sent->getName());
        self::assertSame('3', $sent->getAttributes()['seat']);
        self::assertSame('', $sent->getAttributes()['stale']);

        $sentPermission = $sent->getPermission();
        self::assertInstanceOf(ParticipantPermission::class, $sentPermission);
        self::assertTrue($sentPermission->getCanSubscribe());
        self::assertFalse($sentPermission->getCanPublish());
        self::assertTrue($sentPermission->getCanPublishData());
        self::assertSame(
            [TrackSource::MICROPHONE],
            iterator_to_array($sentPermission->getCanPublishSources()),
        );

        $this->assertVideoGrant(['roomAdmin' => true, 'room' => 'my-room'], $request);

        self::assertSame('Alice B', $participant->getName());
    }

    public function testUpdateParticipantWithoutOptionsSendsOnlyRoomAndIdentity(): void
    {
        $client = $this->client((new ParticipantInfo())->setIdentity('alice'));

        $client->updateParticipant('my-room', 'alice');

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'RoomService', 'UpdateParticipant');

        $sent = $this->decodeRequest(UpdateParticipantRequest::class);
        self::assertSame('my-room', $sent->getRoom());
        self::assertSame('alice', $sent->getIdentity());
        self::assertSame('', $sent->getMetadata());
        self::assertSame('', $sent->getName());
        self::assertNull($sent->getPermission());
        self::assertCount(0, $sent->getAttributes());

        $this->assertVideoGrant(['roomAdmin' => true, 'room' => 'my-room'], $request);
    }

    public function testUpdateSubscriptionsSendsTrackSidsAndSubscribeFlag(): void
    {
        $client = $this->client(new UpdateSubscriptionsResponse());

        $client->updateSubscriptions('my-room', 'alice', ['TR_1', 'TR_2'], true);

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'RoomService', 'UpdateSubscriptions');

        $sent = $this->decodeRequest(UpdateSubscriptionsRequest::class);
        self::assertSame('my-room', $sent->getRoom());
        self::assertSame('alice', $sent->getIdentity());
        self::assertSame(['TR_1', 'TR_2'], iterator_to_array($sent->getTrackSids()));
        self::assertTrue($sent->getSubscribe());

        $this->assertVideoGrant(['roomAdmin' => true, 'room' => 'my-room'], $request);
    }

    public function testUpdateSubscriptionsCanUnsubscribe(): void
    {
        $client = $this->client(new UpdateSubscriptionsResponse());

        $client->updateSubscriptions('my-room', 'alice', ['TR_1'], false);

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'RoomService', 'UpdateSubscriptions');

        $sent = $this->decodeRequest(UpdateSubscriptionsRequest::class);
        self::assertSame('my-room', $sent->getRoom());
        self::assertSame('alice', $sent->getIdentity());
        self::assertFalse($sent->getSubscribe());
        self::assertSame(['TR_1'], iterator_to_array($sent->getTrackSids()));

        $this->assertVideoGrant(['roomAdmin' => true, 'room' => 'my-room'], $request);
    }

    public function testSendDataMapsPayloadKindIdentitiesTopicAndNonce(): void
    {
        $client = $this->client(new SendDataResponse());

        $client->sendData(
            'my-room',
            'hello world',
            Kind::LOSSY,
            new SendDataOptions(
                destinationIdentities: ['alice', 'bob'],
                topic: 'chat',
                nonce: "\x01\x02\x03",
            ),
        );

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'RoomService', 'SendData');

        $sent = $this->decodeRequest(SendDataRequest::class);
        self::assertSame('my-room', $sent->getRoom());
        self::assertSame('hello world', $sent->getData());
        self::assertSame(Kind::LOSSY, $sent->getKind());
        self::assertSame(['alice', 'bob'], iterator_to_array($sent->getDestinationIdentities()));
        self::assertSame('chat', $sent->getTopic());
        self::assertTrue($sent->hasTopic());
        self::assertSame("\x01\x02\x03", $sent->getNonce());

        // destination_sids is deprecated in livekit_room.proto and must never be populated by this SDK.
        self::assertCount(0, $sent->getDestinationSids());

        $this->assertVideoGrant(['roomAdmin' => true, 'room' => 'my-room'], $request);
    }

    public function testSendDataDefaultsToReliableAndOmitsTopic(): void
    {
        $client = $this->client(new SendDataResponse());

        $client->sendData('my-room', 'ping');

        $request = $this->http->lastRequest();
        $this->assertTwirpRequest($request, 'RoomService', 'SendData');

        $sent = $this->decodeRequest(SendDataRequest::class);
        self::assertSame('my-room', $sent->getRoom());
        self::assertSame('ping', $sent->getData());
        self::assertSame(Kind::RELIABLE, $sent->getKind());
        self::assertFalse($sent->hasTopic());
        self::assertCount(0, $sent->getDestinationIdentities());
        self::assertCount(0, $sent->getDestinationSids());
        self::assertSame('', $sent->getNonce());

        $this->assertVideoGrant(['roomAdmin' => true, 'room' => 'my-room'], $request);
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
