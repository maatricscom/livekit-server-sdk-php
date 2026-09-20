<?php

declare(strict_types=1);

namespace LiveKit\Grants;

use LiveKit\Proto\RoomConfiguration;

/**
 * Assembles the LiveKit-specific half of an access token payload.
 *
 * Go's tokenClaims embeds both jwt.RegisteredClaims and ClaimGrants, which
 * flattens them into a single JSON object. AccessToken merges this array with
 * the registered claims at the same level to reproduce that shape.
 */
final class ClaimGrants
{
    private ?string $identity = null;

    private ?string $name = null;

    private ?string $kind = null;

    /** @var list<string>|null */
    private ?array $kindDetails = null;

    private ?VideoGrant $video = null;

    private ?SIPGrant $sip = null;

    private ?AgentGrant $agent = null;

    private ?InferenceGrant $inference = null;

    private ?ObservabilityGrant $observability = null;

    private ?string $roomPreset = null;

    private ?RoomConfiguration $roomConfig = null;

    private ?string $sha256 = null;

    private ?string $metadata = null;

    /** @var array<string, string>|null */
    private ?array $attributes = null;

    public function setIdentity(?string $identity): self
    {
        $this->identity = $identity;

        return $this;
    }

    public function getIdentity(): ?string
    {
        return $this->identity;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function setKind(?string $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    /**
     * @param list<string>|null $kindDetails
     */
    public function setKindDetails(?array $kindDetails): self
    {
        $this->kindDetails = $kindDetails;

        return $this;
    }

    public function setVideo(?VideoGrant $video): self
    {
        $this->video = $video;

        return $this;
    }

    public function getVideo(): ?VideoGrant
    {
        return $this->video;
    }

    public function setSip(?SIPGrant $sip): self
    {
        $this->sip = $sip;

        return $this;
    }

    public function setAgent(?AgentGrant $agent): self
    {
        $this->agent = $agent;

        return $this;
    }

    public function setInference(?InferenceGrant $inference): self
    {
        $this->inference = $inference;

        return $this;
    }

    public function setObservability(?ObservabilityGrant $observability): self
    {
        $this->observability = $observability;

        return $this;
    }

    public function setRoomPreset(?string $roomPreset): self
    {
        $this->roomPreset = $roomPreset;

        return $this;
    }

    public function setRoomConfig(?RoomConfiguration $roomConfig): self
    {
        $this->roomConfig = $roomConfig;

        return $this;
    }

    public function getRoomConfig(): ?RoomConfiguration
    {
        return $this->roomConfig;
    }

    public function setSha256(?string $sha256): self
    {
        $this->sha256 = $sha256;

        return $this;
    }

    public function setMetadata(?string $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * @param array<string, string>|null $attributes
     */
    public function setAttributes(?array $attributes): self
    {
        $this->attributes = $attributes;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $claims = [];

        if ($this->identity !== null && $this->identity !== '') {
            $claims['identity'] = $this->identity;
        }

        if ($this->name !== null && $this->name !== '') {
            $claims['name'] = $this->name;
        }

        if ($this->kind !== null && $this->kind !== '') {
            $claims['kind'] = $this->kind;
        }

        if ($this->kindDetails !== null && $this->kindDetails !== []) {
            $claims['kindDetails'] = array_values($this->kindDetails);
        }

        foreach ([
            'video' => $this->video,
            'sip' => $this->sip,
            'agent' => $this->agent,
            'inference' => $this->inference,
            'observability' => $this->observability,
        ] as $key => $grant) {
            if ($grant === null) {
                continue;
            }

            $serialized = $grant->toArray();

            // Go's `json:"...,omitempty"` on a POINTER field tests nil, not emptiness:
            // a grant that was explicitly set still serializes, as {} when it carries
            // no permissions. json_encode([]) would emit `[]`, which Go cannot
            // unmarshal into *VideoGrant, hence stdClass to force a JSON object.
            $claims[$key] = $serialized === [] ? new \stdClass() : $serialized;
        }

        if ($this->sha256 !== null && $this->sha256 !== '') {
            $claims['sha256'] = $this->sha256;
        }

        if ($this->metadata !== null && $this->metadata !== '') {
            $claims['metadata'] = $this->metadata;
        }

        if ($this->roomPreset !== null && $this->roomPreset !== '') {
            $claims['roomPreset'] = $this->roomPreset;
        }

        if ($this->roomConfig !== null) {
            // Go marshals this field with protojson, not encoding/json, so the shape
            // has to come from the protobuf runtime rather than from a hand-rolled
            // array -- field names, enum spellings and well-known types all differ.
            $encoded = json_decode($this->roomConfig->serializeToJsonString(), true, 512, JSON_THROW_ON_ERROR);

            // Set but empty is still set: a pointer field, so it serializes as {}.
            $claims['roomConfig'] = is_array($encoded) && $encoded !== [] ? $encoded : new \stdClass();
        }

        // Omitted when empty: json_encode([]) would emit a JSON array, but the
        // server expects an object. Go omits it for the same reason.
        if ($this->attributes !== null && $this->attributes !== []) {
            $claims['attributes'] = $this->attributes;
        }

        return $claims;
    }
}
