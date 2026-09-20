<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Proto\EncodingOptions;
use LiveKit\Proto\WebhookConfig;

final readonly class TrackCompositeOptions extends EgressBaseOptions
{
    /**
     * @param int|EncodingOptions|null $encodingOptions an EncodingOptionsPreset constant, or advanced options
     * @param list<WebhookConfig>|null $webhooks
     */
    public function __construct(
        public ?string $audioTrackId = null,
        public ?string $videoTrackId = null,
        public int|EncodingOptions|null $encodingOptions = null,
        ?array $webhooks = null,
    ) {
        parent::__construct($webhooks);
    }
}
