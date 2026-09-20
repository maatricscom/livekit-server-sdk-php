<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Proto\IngressAudioOptions;
use LiveKit\Proto\IngressVideoOptions;

/**
 * Maps to livekit.UpdateIngressRequest. The ingress id is a method argument,
 * not an option. The deprecated `bypass_transcoding` field is not exposed.
 */
final readonly class UpdateIngressOptions
{
    public function __construct(
        public ?string $name = null,
        public ?string $roomName = null,
        public ?string $participantIdentity = null,
        public ?string $participantName = null,
        public ?string $participantMetadata = null,
        public ?bool $enableTranscoding = null,
        public ?bool $enabled = null,
        public ?IngressAudioOptions $audio = null,
        public ?IngressVideoOptions $video = null,
    ) {
    }
}
