<?php

declare(strict_types=1);

namespace LiveKit\Grants;

use LiveKit\Enums\ProtoEnum;
use LiveKit\Exceptions\ConfigurationException;
use LiveKit\Proto\TrackSource;

/**
 * The `video` claim of a LiveKit access token.
 *
 * Field names and JSON keys mirror auth/grants.go in livekit/protocol.
 *
 * Six fields are nullable on purpose. They are *bool in Go, so they carry three
 * states: unset (the server applies its default), explicitly true, explicitly
 * false. Serializing them with any kind of truthiness filter would drop an
 * explicit `false` and silently grant the permission it was meant to deny.
 */
final readonly class VideoGrant
{
    /**
     * @param list<string|int>|null $canPublishSources Track sources this participant may publish, which
     *                                                  supersedes $canPublish when set. Either the names
     *                                                  — camera, microphone, screen_share,
     *                                                  screen_share_audio — or LiveKit\Proto\TrackSource
     *                                                  constants. Validated on serialization.
     */
    public function __construct(
        public bool $roomCreate = false,
        public bool $roomList = false,
        public bool $roomRecord = false,
        public bool $roomAdmin = false,
        public bool $roomJoin = false,
        public ?string $room = null,
        public ?bool $canPublish = null,
        public ?bool $canSubscribe = null,
        public ?bool $canPublishData = null,
        public ?array $canPublishSources = null,
        public ?bool $canUpdateOwnMetadata = null,
        public bool $ingressAdmin = false,
        public bool $hidden = false,
        public bool $recorder = false,
        public bool $agent = false,
        public ?bool $canSubscribeMetrics = null,
        public ?bool $canManageAgentSession = null,
        public ?string $destinationRoom = null,
    ) {
    }

    /**
     * Normalizes one track source to the name LiveKit expects.
     *
     * The server does TrackSource_value[strings.ToUpper(s)] and falls back to
     * UNKNOWN, which is the failure worth preventing here: a typo like
     * "screenshare" is not rejected anywhere, it simply grants nothing, and the
     * participant discovers it when publishing fails for no stated reason.
     *
     * The accepted set comes from the generated enum rather than a list written
     * here, so it follows the pinned protocol instead of drifting from it.
     */
    private static function trackSource(string|int $source): string
    {
        // TrackSource::name() throws for a value the enum does not define, and that
        // exception is the protobuf runtime's, not ours.
        try {
            $name = is_int($source) ? TrackSource::name($source) : strtoupper($source);
        } catch (\Throwable) {
            $name = null;
        }

        $known = ProtoEnum::names(TrackSource::class);

        if (!is_string($name) || !isset($known[$name]) || $name === 'UNKNOWN') {
            throw ConfigurationException::unknownTrackSource(
                is_int($source) ? (string) $source : $source,
                array_map(strtolower(...), array_keys(array_diff_key($known, ['UNKNOWN' => 0])))
            );
        }

        return strtolower($name);
    }

    /**
     * @return array<string, bool|string|list<string>>
     */
    public function toArray(): array
    {
        $grant = [];

        // Plain bools use omitempty semantics: present only when true.
        foreach ([
            'roomCreate' => $this->roomCreate,
            'roomList' => $this->roomList,
            'roomRecord' => $this->roomRecord,
            'roomAdmin' => $this->roomAdmin,
            'roomJoin' => $this->roomJoin,
        ] as $key => $value) {
            if ($value) {
                $grant[$key] = true;
            }
        }

        if ($this->room !== null && $this->room !== '') {
            $grant['room'] = $this->room;
        }

        // Tri-state fields: null omits, false emits false, true emits true.
        foreach ([
            'canPublish' => $this->canPublish,
            'canSubscribe' => $this->canSubscribe,
            'canPublishData' => $this->canPublishData,
        ] as $key => $value) {
            if ($value !== null) {
                $grant[$key] = $value;
            }
        }

        if ($this->canPublishSources !== null && $this->canPublishSources !== []) {
            $grant['canPublishSources'] = array_map(self::trackSource(...), array_values($this->canPublishSources));
        }

        if ($this->canUpdateOwnMetadata !== null) {
            $grant['canUpdateOwnMetadata'] = $this->canUpdateOwnMetadata;
        }

        foreach ([
            'ingressAdmin' => $this->ingressAdmin,
            'hidden' => $this->hidden,
            'recorder' => $this->recorder,
            'agent' => $this->agent,
        ] as $key => $value) {
            if ($value) {
                $grant[$key] = true;
            }
        }

        if ($this->canSubscribeMetrics !== null) {
            $grant['canSubscribeMetrics'] = $this->canSubscribeMetrics;
        }

        if ($this->canManageAgentSession !== null) {
            $grant['canManageAgentSession'] = $this->canManageAgentSession;
        }

        if ($this->destinationRoom !== null && $this->destinationRoom !== '') {
            $grant['destinationRoom'] = $this->destinationRoom;
        }

        return $grant;
    }
}
