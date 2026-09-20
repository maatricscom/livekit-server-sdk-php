<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Proto\EncodingOptions;
use LiveKit\Proto\WebhookConfig;

final readonly class WebOptions extends EgressBaseOptions
{
    /**
     * @param int|EncodingOptions|null $encodingOptions an EncodingOptionsPreset constant, or advanced options
     * @param list<WebhookConfig>|null $webhooks
     */
    public function __construct(
        public int|EncodingOptions|null $encodingOptions = null,
        public ?bool $audioOnly = null,
        public ?bool $videoOnly = null,
        public ?bool $awaitStartSignal = null,
        ?array $webhooks = null,
    ) {
        parent::__construct($webhooks);
    }
}
