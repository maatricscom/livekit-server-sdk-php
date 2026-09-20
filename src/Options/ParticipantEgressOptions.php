<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Proto\EncodingOptions;
use LiveKit\Proto\WebhookConfig;

final readonly class ParticipantEgressOptions extends EgressBaseOptions
{
    /**
     * @param bool|null $screenShare true captures screen_share + screen_share_audio, false camera + microphone
     * @param int|EncodingOptions|null $encodingOptions an EncodingOptionsPreset constant, or advanced options
     * @param list<WebhookConfig>|null $webhooks
     */
    public function __construct(
        public ?bool $screenShare = null,
        public int|EncodingOptions|null $encodingOptions = null,
        ?array $webhooks = null,
    ) {
        parent::__construct($webhooks);
    }
}
