<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Proto\IngressAudioOptions;
use LiveKit\Proto\IngressInput;
use LiveKit\Proto\IngressVideoOptions;

/**
 * Maps to livekit.CreateIngressRequest.
 *
 * The deprecated `bypass_transcoding` proto field is intentionally not exposed;
 * upstream replaced it with `enable_transcoding`.
 */
final readonly class CreateIngressOptions
{
    /**
     * @param int $inputType one of LiveKit\Proto\IngressInput::RTMP_INPUT|WHIP_INPUT|URL_INPUT
     * @param string|null $url media source, URL_INPUT only
     * @param bool|null $enableTranscoding null leaves the server default in place
     * @param bool|null $enabled null leaves the server default (true) in place
     */
    public function __construct(
        public int $inputType = IngressInput::RTMP_INPUT,
        public ?string $name = null,
        public ?string $roomName = null,
        public ?string $participantIdentity = null,
        public ?string $participantName = null,
        public ?string $participantMetadata = null,
        public ?string $url = null,
        public ?bool $enableTranscoding = null,
        public ?bool $enabled = null,
        public ?IngressAudioOptions $audio = null,
        public ?IngressVideoOptions $video = null,
    ) {
    }
}
