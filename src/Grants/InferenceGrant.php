<?php

declare(strict_types=1);

namespace LiveKit\Grants;

/**
 * The `inference` claim. `perform` grants all inference features (LLM, STT, TTS).
 */
final readonly class InferenceGrant
{
    public function __construct(public bool $perform = false)
    {
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return $this->perform ? ['perform' => true] : [];
    }
}
