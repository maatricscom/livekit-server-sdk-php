<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Proto\RoomAgentDispatch;
use LiveKit\Proto\RoomEgress;

/**
 * Maps to livekit.CreateRoomRequest.
 */
final readonly class CreateRoomOptions
{
    /**
     * @param array<string, string>|null  $tags   livekit.CreateRoomRequest.tags
     * @param list<RoomAgentDispatch>|null $agents livekit.CreateRoomRequest.agents
     */
    public function __construct(
        public string $name,
        public ?string $roomPreset = null,
        public ?int $emptyTimeout = null,
        public ?int $departureTimeout = null,
        public ?int $maxParticipants = null,
        public ?string $nodeId = null,
        public ?string $metadata = null,
        public ?array $tags = null,
        public ?RoomEgress $egress = null,
        public ?int $minPlayoutDelay = null,
        public ?int $maxPlayoutDelay = null,
        public ?bool $syncStreams = null,
        public ?bool $replayEnabled = null,
        public ?array $agents = null,
    ) {
    }
}
