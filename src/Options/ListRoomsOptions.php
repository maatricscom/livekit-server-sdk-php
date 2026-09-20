<?php

declare(strict_types=1);

namespace LiveKit\Options;

/**
 * Maps to livekit.ListRoomsRequest.
 */
final readonly class ListRoomsOptions
{
    /**
     * @param list<string>|null $names livekit.ListRoomsRequest.names — when set, only rooms whose name matches
     */
    public function __construct(
        public ?array $names = null,
    ) {
    }
}
