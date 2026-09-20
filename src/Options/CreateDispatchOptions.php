<?php

declare(strict_types=1);

namespace LiveKit\Options;

/**
 * Maps to livekit.CreateAgentDispatchRequest. `room` and `agent_name` are
 * method arguments because both are required by the service.
 */
final readonly class CreateDispatchOptions
{
    /**
     * @param array<string, string> $attributes maps to the `attributes` map field
     * @param int|null $restartPolicy one of LiveKit\Proto\JobRestartPolicy::JRP_ON_FAILURE|JRP_NEVER; cloud only
     */
    public function __construct(
        public ?string $metadata = null,
        public ?string $deployment = null,
        public array $attributes = [],
        public ?int $restartPolicy = null,
    ) {
    }
}
