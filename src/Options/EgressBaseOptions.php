<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Proto\WebhookConfig;

/**
 * Carries the `repeated WebhookConfig webhooks` field shared by every v1 egress request.
 */
abstract readonly class EgressBaseOptions
{
    /**
     * @param list<WebhookConfig>|null $webhooks
     */
    public function __construct(
        public ?array $webhooks = null,
    ) {
    }
}
