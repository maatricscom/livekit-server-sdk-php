<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Proto\EncodingOptions;
use LiveKit\Proto\WebhookConfig;

final readonly class RoomCompositeOptions extends EgressBaseOptions
{
    /**
     * @param int|EncodingOptions|null $encodingOptions an EncodingOptionsPreset constant, or advanced options
     * @param int|null $audioMixing an AudioMixing constant
     * @param list<WebhookConfig>|null $webhooks
     */
    public function __construct(
        public ?string $layout = null,
        public int|EncodingOptions|null $encodingOptions = null,
        public ?bool $audioOnly = null,
        public ?bool $videoOnly = null,
        public ?string $customBaseUrl = null,
        public ?int $audioMixing = null,
        ?array $webhooks = null,
    ) {
        parent::__construct($webhooks);
    }
}
