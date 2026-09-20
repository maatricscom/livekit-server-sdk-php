<?php

declare(strict_types=1);

namespace LiveKit\Contracts;

use LiveKit\Options\CreateRoomOptions;
use LiveKit\Options\ListRoomsOptions;
use LiveKit\Options\SendDataOptions;
use LiveKit\Options\UpdateParticipantOptions;
use LiveKit\Proto\DataPacket\Kind;
use LiveKit\Proto\DeleteRoomResponse;
use LiveKit\Proto\ForwardParticipantResponse;
use LiveKit\Proto\MoveParticipantResponse;
use LiveKit\Proto\MuteRoomTrackResponse;
use LiveKit\Proto\ParticipantInfo;
use LiveKit\Proto\PerformRpcResponse;
use LiveKit\Proto\RemoveParticipantResponse;
use LiveKit\Proto\Room;
use LiveKit\Proto\SendDataResponse;
use LiveKit\Proto\UpdateSubscriptionsResponse;

/**
 * The 14 rpcs of livekit.RoomService.
 */
interface RoomServiceClientInterface
{
    public function createRoom(CreateRoomOptions $options): Room;

    /**
     * @return list<Room>
     */
    public function listRooms(?ListRoomsOptions $options = null): array;

    public function deleteRoom(string $room): DeleteRoomResponse;

    /**
     * @return list<ParticipantInfo>
     */
    public function listParticipants(string $room): array;

    public function getParticipant(string $room, string $identity): ParticipantInfo;

    public function removeParticipant(string $room, string $identity): RemoveParticipantResponse;

    public function mutePublishedTrack(
        string $room,
        string $identity,
        string $trackSid,
        bool $muted,
    ): MuteRoomTrackResponse;

    public function updateParticipant(
        string $room,
        string $identity,
        ?UpdateParticipantOptions $options = null,
    ): ParticipantInfo;

    /**
     * @param list<string> $trackSids
     */
    public function updateSubscriptions(
        string $room,
        string $identity,
        array $trackSids,
        bool $subscribe,
    ): UpdateSubscriptionsResponse;

    public function sendData(
        string $room,
        string $data,
        int $kind = Kind::RELIABLE,
        ?SendDataOptions $options = null,
    ): SendDataResponse;

    public function updateRoomMetadata(string $room, string $metadata): Room;

    public function forwardParticipant(
        string $room,
        string $identity,
        string $destinationRoom,
    ): ForwardParticipantResponse;

    public function moveParticipant(
        string $room,
        string $identity,
        string $destinationRoom,
    ): MoveParticipantResponse;

    public function performRpc(
        string $room,
        string $destinationIdentity,
        string $method,
        string $payload,
        ?int $responseTimeoutMs = null,
    ): PerformRpcResponse;
}
