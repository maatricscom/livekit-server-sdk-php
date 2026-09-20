<?php

declare(strict_types=1);

namespace LiveKit\Services;

use LiveKit\Grants\VideoGrant;
use LiveKit\Options\CreateRoomOptions;
use LiveKit\Options\ListRoomsOptions;
use LiveKit\Proto\CreateRoomRequest;
use LiveKit\Proto\DeleteRoomRequest;
use LiveKit\Proto\DeleteRoomResponse;
use LiveKit\Proto\ListParticipantsRequest;
use LiveKit\Proto\ListParticipantsResponse;
use LiveKit\Proto\ListRoomsRequest;
use LiveKit\Proto\ListRoomsResponse;
use LiveKit\Proto\ParticipantInfo;
use LiveKit\Proto\Room;

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
}
