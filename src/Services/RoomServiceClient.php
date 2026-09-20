<?php

declare(strict_types=1);

namespace LiveKit\Services;

use LiveKit\Grants\VideoGrant;
use LiveKit\Options\CreateRoomOptions;
use LiveKit\Options\ListRoomsOptions;
use LiveKit\Options\SendDataOptions;
use LiveKit\Options\UpdateParticipantOptions;
use LiveKit\Proto\CreateRoomRequest;
use LiveKit\Proto\DataPacket\Kind;
use LiveKit\Proto\DeleteRoomRequest;
use LiveKit\Proto\DeleteRoomResponse;
use LiveKit\Proto\ForwardParticipantRequest;
use LiveKit\Proto\ForwardParticipantResponse;
use LiveKit\Proto\ListParticipantsRequest;
use LiveKit\Proto\ListParticipantsResponse;
use LiveKit\Proto\ListRoomsRequest;
use LiveKit\Proto\ListRoomsResponse;
use LiveKit\Proto\MoveParticipantRequest;
use LiveKit\Proto\MoveParticipantResponse;
use LiveKit\Proto\MuteRoomTrackRequest;
use LiveKit\Proto\MuteRoomTrackResponse;
use LiveKit\Proto\ParticipantInfo;
use LiveKit\Proto\RemoveParticipantResponse;
use LiveKit\Proto\Room;
use LiveKit\Proto\RoomParticipantIdentity;
use LiveKit\Proto\SendDataRequest;
use LiveKit\Proto\SendDataResponse;
use LiveKit\Proto\UpdateParticipantRequest;
use LiveKit\Proto\UpdateRoomMetadataRequest;
use LiveKit\Proto\UpdateSubscriptionsRequest;
use LiveKit\Proto\UpdateSubscriptionsResponse;

final class RoomServiceClient extends ServiceBase
{
    private const SERVICE = 'RoomService';

    public function createRoom(CreateRoomOptions $options): Room
    {
        $request = new CreateRoomRequest();
        $request->setName($options->name);

        if ($options->roomPreset !== null) {
            $request->setRoomPreset($options->roomPreset);
        }

        if ($options->emptyTimeout !== null) {
            $request->setEmptyTimeout($options->emptyTimeout);
        }

        if ($options->departureTimeout !== null) {
            $request->setDepartureTimeout($options->departureTimeout);
        }

        if ($options->maxParticipants !== null) {
            $request->setMaxParticipants($options->maxParticipants);
        }

        if ($options->nodeId !== null) {
            $request->setNodeId($options->nodeId);
        }

        if ($options->metadata !== null) {
            $request->setMetadata($options->metadata);
        }

        if ($options->tags !== null) {
            $request->setTags($options->tags);
        }

        if ($options->egress !== null) {
            $request->setEgress($options->egress);
        }

        if ($options->minPlayoutDelay !== null) {
            $request->setMinPlayoutDelay($options->minPlayoutDelay);
        }

        if ($options->maxPlayoutDelay !== null) {
            $request->setMaxPlayoutDelay($options->maxPlayoutDelay);
        }

        if ($options->syncStreams !== null) {
            $request->setSyncStreams($options->syncStreams);
        }

        if ($options->replayEnabled !== null) {
            $request->setReplayEnabled($options->replayEnabled);
        }

        if ($options->agents !== null) {
            $request->setAgents($options->agents);
        }

        $response = $this->rpc(
            self::SERVICE,
            'CreateRoom',
            $request,
            Room::class,
            $this->authHeader(new VideoGrant(roomCreate: true)),
        );

        assert($response instanceof Room);

        return $response;
    }

    /**
     * @return list<Room>
     */
    public function listRooms(?ListRoomsOptions $options = null): array
    {
        $request = new ListRoomsRequest();

        if ($options?->names !== null) {
            $request->setNames($options->names);
        }

        $response = $this->rpc(
            self::SERVICE,
            'ListRooms',
            $request,
            ListRoomsResponse::class,
            $this->authHeader(new VideoGrant(roomList: true)),
        );

        assert($response instanceof ListRoomsResponse);

        $rooms = [];

        foreach ($response->getRooms() as $room) {
            $rooms[] = $room;
        }

        return $rooms;
    }

    /**
     * Requires the roomCreate grant — not roomAdmin. This mirrors LiveKit's Node SDK and the
     * server-side check; it looks wrong and is correct.
     */
    public function deleteRoom(string $room): DeleteRoomResponse
    {
        $request = new DeleteRoomRequest();
        $request->setRoom($room);

        $response = $this->rpc(
            self::SERVICE,
            'DeleteRoom',
            $request,
            DeleteRoomResponse::class,
            $this->authHeader(new VideoGrant(roomCreate: true)),
        );

        assert($response instanceof DeleteRoomResponse);

        return $response;
    }

    /**
     * @return list<ParticipantInfo>
     */
    public function listParticipants(string $room): array
    {
        $request = new ListParticipantsRequest();
        $request->setRoom($room);

        $response = $this->rpc(
            self::SERVICE,
            'ListParticipants',
            $request,
            ListParticipantsResponse::class,
            $this->authHeader(new VideoGrant(roomAdmin: true, room: $room)),
        );

        assert($response instanceof ListParticipantsResponse);

        $participants = [];

        foreach ($response->getParticipants() as $participant) {
            $participants[] = $participant;
        }

        return $participants;
    }

    public function getParticipant(string $room, string $identity): ParticipantInfo
    {
        $request = new RoomParticipantIdentity();
        $request->setRoom($room);
        $request->setIdentity($identity);

        $response = $this->rpc(
            self::SERVICE,
            'GetParticipant',
            $request,
            ParticipantInfo::class,
            $this->authHeader(new VideoGrant(roomAdmin: true, room: $room)),
        );

        assert($response instanceof ParticipantInfo);

        return $response;
    }

    public function removeParticipant(string $room, string $identity): RemoveParticipantResponse
    {
        $request = new RoomParticipantIdentity();
        $request->setRoom($room);
        $request->setIdentity($identity);

        $response = $this->rpc(
            self::SERVICE,
            'RemoveParticipant',
            $request,
            RemoveParticipantResponse::class,
            $this->authHeader(new VideoGrant(roomAdmin: true, room: $room)),
        );

        assert($response instanceof RemoveParticipantResponse);

        return $response;
    }

    public function mutePublishedTrack(
        string $room,
        string $identity,
        string $trackSid,
        bool $muted,
    ): MuteRoomTrackResponse {
        $request = new MuteRoomTrackRequest();
        $request->setRoom($room);
        $request->setIdentity($identity);
        $request->setTrackSid($trackSid);
        $request->setMuted($muted);

        $response = $this->rpc(
            self::SERVICE,
            'MutePublishedTrack',
            $request,
            MuteRoomTrackResponse::class,
            $this->authHeader(new VideoGrant(roomAdmin: true, room: $room)),
        );

        assert($response instanceof MuteRoomTrackResponse);

        return $response;
    }

    public function updateParticipant(
        string $room,
        string $identity,
        ?UpdateParticipantOptions $options = null,
    ): ParticipantInfo {
        $request = new UpdateParticipantRequest();
        $request->setRoom($room);
        $request->setIdentity($identity);

        if ($options?->metadata !== null) {
            $request->setMetadata($options->metadata);
        }

        if ($options?->permission !== null) {
            $request->setPermission($options->permission);
        }

        if ($options?->name !== null) {
            $request->setName($options->name);
        }

        if ($options?->attributes !== null) {
            $request->setAttributes($options->attributes);
        }

        $response = $this->rpc(
            self::SERVICE,
            'UpdateParticipant',
            $request,
            ParticipantInfo::class,
            $this->authHeader(new VideoGrant(roomAdmin: true, room: $room)),
        );

        assert($response instanceof ParticipantInfo);

        return $response;
    }

    /**
     * @param list<string> $trackSids
     */
    public function updateSubscriptions(
        string $room,
        string $identity,
        array $trackSids,
        bool $subscribe,
    ): UpdateSubscriptionsResponse {
        $request = new UpdateSubscriptionsRequest();
        $request->setRoom($room);
        $request->setIdentity($identity);
        $request->setTrackSids($trackSids);
        $request->setSubscribe($subscribe);

        $response = $this->rpc(
            self::SERVICE,
            'UpdateSubscriptions',
            $request,
            UpdateSubscriptionsResponse::class,
            $this->authHeader(new VideoGrant(roomAdmin: true, room: $room)),
        );

        assert($response instanceof UpdateSubscriptionsResponse);

        return $response;
    }

    /**
     * @param string $data raw bytes of the payload
     * @param int    $kind one of LiveKit\Proto\DataPacket\Kind
     */
    public function sendData(
        string $room,
        string $data,
        int $kind = Kind::RELIABLE,
        ?SendDataOptions $options = null,
    ): SendDataResponse {
        $request = new SendDataRequest();
        $request->setRoom($room);
        $request->setData($data);
        $request->setKind($kind);

        if ($options?->destinationIdentities !== null) {
            $request->setDestinationIdentities($options->destinationIdentities);
        }

        if ($options?->topic !== null) {
            $request->setTopic($options->topic);
        }

        if ($options?->nonce !== null) {
            $request->setNonce($options->nonce);
        }

        $response = $this->rpc(
            self::SERVICE,
            'SendData',
            $request,
            SendDataResponse::class,
            $this->authHeader(new VideoGrant(roomAdmin: true, room: $room)),
        );

        assert($response instanceof SendDataResponse);

        return $response;
    }

    public function updateRoomMetadata(string $room, string $metadata): Room
    {
        $request = new UpdateRoomMetadataRequest();
        $request->setRoom($room);
        $request->setMetadata($metadata);

        $response = $this->rpc(
            self::SERVICE,
            'UpdateRoomMetadata',
            $request,
            Room::class,
            $this->authHeader(new VideoGrant(roomAdmin: true, room: $room)),
        );

        assert($response instanceof Room);

        return $response;
    }

    /**
     * Cloud-only. Forwards a participant's tracks into another room.
     */
    public function forwardParticipant(
        string $room,
        string $identity,
        string $destinationRoom,
    ): ForwardParticipantResponse {
        $request = new ForwardParticipantRequest();
        $request->setRoom($room);
        $request->setIdentity($identity);
        $request->setDestinationRoom($destinationRoom);

        $response = $this->rpc(
            self::SERVICE,
            'ForwardParticipant',
            $request,
            ForwardParticipantResponse::class,
            $this->authHeader(new VideoGrant(
                roomAdmin: true,
                room: $room,
                destinationRoom: $destinationRoom,
            )),
        );

        assert($response instanceof ForwardParticipantResponse);

        return $response;
    }

    /**
     * Cloud-only. Moves a participant out of the current room and into the destination room.
     */
    public function moveParticipant(
        string $room,
        string $identity,
        string $destinationRoom,
    ): MoveParticipantResponse {
        $request = new MoveParticipantRequest();
        $request->setRoom($room);
        $request->setIdentity($identity);
        $request->setDestinationRoom($destinationRoom);

        $response = $this->rpc(
            self::SERVICE,
            'MoveParticipant',
            $request,
            MoveParticipantResponse::class,
            $this->authHeader(new VideoGrant(
                roomAdmin: true,
                room: $room,
                destinationRoom: $destinationRoom,
            )),
        );

        assert($response instanceof MoveParticipantResponse);

        return $response;
    }
}
