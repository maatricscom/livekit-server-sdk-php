<?php

declare(strict_types=1);

namespace LiveKit\Services;

use LiveKit\Contracts\RoomServiceClientInterface;
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
use LiveKit\Proto\PerformRpcRequest;
use LiveKit\Proto\PerformRpcResponse;
use LiveKit\Proto\RemoveParticipantResponse;
use LiveKit\Proto\Room;
use LiveKit\Proto\RoomParticipantIdentity;
use LiveKit\Proto\SendDataRequest;
use LiveKit\Proto\SendDataResponse;
use LiveKit\Proto\UpdateParticipantRequest;
use LiveKit\Proto\UpdateRoomMetadataRequest;
use LiveKit\Proto\UpdateSubscriptionsRequest;
use LiveKit\Proto\UpdateSubscriptionsResponse;

final class RoomServiceClient extends ServiceBase implements RoomServiceClientInterface
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

        return $this->rpc(
            self::SERVICE,
            'CreateRoom',
            $request,
            Room::class,
            $this->authHeader(new VideoGrant(roomCreate: true)),
        );
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

        $rooms = [];

        foreach ($response->getRooms() as $room) {
            // RepeatedField's iterator has no generic value type, so foreach yields
            // mixed here — unlike the rpc() return, which the analyser infers.
            assert($room instanceof Room);
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

        return $this->rpc(
            self::SERVICE,
            'DeleteRoom',
            $request,
            DeleteRoomResponse::class,
            $this->authHeader(new VideoGrant(roomCreate: true)),
        );
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

        $participants = [];

        foreach ($response->getParticipants() as $participant) {
            // RepeatedField's iterator has no generic value type, so foreach yields
            // mixed here — unlike the rpc() return, which the analyser infers.
            assert($participant instanceof ParticipantInfo);
            $participants[] = $participant;
        }

        return $participants;
    }

    public function getParticipant(string $room, string $identity): ParticipantInfo
    {
        $request = new RoomParticipantIdentity();
        $request->setRoom($room);
        $request->setIdentity($identity);

        return $this->rpc(
            self::SERVICE,
            'GetParticipant',
            $request,
            ParticipantInfo::class,
            $this->authHeader(new VideoGrant(roomAdmin: true, room: $room)),
        );
    }

    public function removeParticipant(string $room, string $identity): RemoveParticipantResponse
    {
        $request = new RoomParticipantIdentity();
        $request->setRoom($room);
        $request->setIdentity($identity);

        return $this->rpc(
            self::SERVICE,
            'RemoveParticipant',
            $request,
            RemoveParticipantResponse::class,
            $this->authHeader(new VideoGrant(roomAdmin: true, room: $room)),
        );
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

        return $this->rpc(
            self::SERVICE,
            'MutePublishedTrack',
            $request,
            MuteRoomTrackResponse::class,
            $this->authHeader(new VideoGrant(roomAdmin: true, room: $room)),
        );
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

        return $this->rpc(
            self::SERVICE,
            'UpdateParticipant',
            $request,
            ParticipantInfo::class,
            $this->authHeader(new VideoGrant(roomAdmin: true, room: $room)),
        );
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

        return $this->rpc(
            self::SERVICE,
            'UpdateSubscriptions',
            $request,
            UpdateSubscriptionsResponse::class,
            $this->authHeader(new VideoGrant(roomAdmin: true, room: $room)),
        );
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

        return $this->rpc(
            self::SERVICE,
            'SendData',
            $request,
            SendDataResponse::class,
            $this->authHeader(new VideoGrant(roomAdmin: true, room: $room)),
        );
    }

    public function updateRoomMetadata(string $room, string $metadata): Room
    {
        $request = new UpdateRoomMetadataRequest();
        $request->setRoom($room);
        $request->setMetadata($metadata);

        return $this->rpc(
            self::SERVICE,
            'UpdateRoomMetadata',
            $request,
            Room::class,
            $this->authHeader(new VideoGrant(roomAdmin: true, room: $room)),
        );
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

        return $this->rpc(
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

        return $this->rpc(
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
    }

    public function performRpc(
        string $room,
        string $destinationIdentity,
        string $method,
        string $payload,
        ?int $responseTimeoutMs = null,
    ): PerformRpcResponse {
        $request = new PerformRpcRequest();
        $request->setRoom($room);
        $request->setDestinationIdentity($destinationIdentity);
        $request->setMethod($method);
        $request->setPayload($payload);

        if ($responseTimeoutMs !== null) {
            $request->setResponseTimeoutMs($responseTimeoutMs);
        }

        return $this->rpc(
            self::SERVICE,
            'PerformRpc',
            $request,
            PerformRpcResponse::class,
            $this->authHeader(new VideoGrant(roomAdmin: true, room: $room)),
        );
    }
}
