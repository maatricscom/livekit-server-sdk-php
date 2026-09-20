<?php

declare(strict_types=1);

namespace LiveKit\Grants;

/**
 * The `observability` claim. `write` grants publishing observability data.
 */
final readonly class ObservabilityGrant
{
    public function __construct(public bool $write = false)
    {
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return $this->write ? ['write' => true] : [];
    }
}
