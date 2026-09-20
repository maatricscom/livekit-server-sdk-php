<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Proto\ParticipantPermission;

/**
 * Maps to livekit.UpdateParticipantRequest (minus room/identity, which are positional arguments).
 */
final readonly class UpdateParticipantOptions
{
    /**
     * @param array<string, string>|null $attributes livekit.UpdateParticipantRequest.attributes —
     *                                               only the given keys are updated; an empty string deletes a key
     */
    public function __construct(
        public ?string $metadata = null,
        public ?ParticipantPermission $permission = null,
        public ?string $name = null,
        public ?array $attributes = null,
    ) {
    }
}
