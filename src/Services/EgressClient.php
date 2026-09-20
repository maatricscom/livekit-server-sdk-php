<?php

declare(strict_types=1);

namespace LiveKit\Services;

use Google\Protobuf\Internal\Message;
use LiveKit\Contracts\EgressClientInterface;
use LiveKit\Grants\VideoGrant;
use LiveKit\Options\EncodedOutputs;
use LiveKit\Options\RoomCompositeOptions;
use LiveKit\Proto\AudioMixing;
use LiveKit\Proto\EgressInfo;
use LiveKit\Proto\EncodedFileOutput;
use LiveKit\Proto\EncodingOptions;
use LiveKit\Proto\ImageOutput;
use LiveKit\Proto\ParticipantEgressRequest;
use LiveKit\Proto\RoomCompositeEgressRequest;
use LiveKit\Proto\SegmentedFileOutput;
use LiveKit\Proto\StreamOutput;
use LiveKit\Proto\TrackCompositeEgressRequest;
use LiveKit\Proto\WebEgressRequest;

/**
 * Client for the livekit.Egress Twirp service. Every rpc authenticates with roomRecord.
 *
 * The `implements EgressClientInterface` clause is added in Step 54, after all ten methods exist.
 */
final class EgressClient extends ServiceBase
{
    private const SERVICE = 'Egress';

    public function startRoomCompositeEgress(
        string $roomName,
        EncodedOutputs|EncodedFileOutput|StreamOutput|SegmentedFileOutput $output,
        ?RoomCompositeOptions $options = null,
    ): EgressInfo {
        $resolved = $this->resolveOutputs($output);

        $request = new RoomCompositeEgressRequest();
        $request->setRoomName($roomName);
        $request->setLayout($options->layout ?? '');
        $request->setAudioOnly($options->audioOnly ?? false);
        $request->setVideoOnly($options->videoOnly ?? false);
        $request->setCustomBaseUrl($options->customBaseUrl ?? '');
        $request->setAudioMixing($options->audioMixing ?? AudioMixing::DEFAULT_MIXING);
        $request->setWebhooks($options->webhooks ?? []);

        $this->applyOutputArrays($request, $resolved);
        $this->applyLegacyOutput($request, $resolved);
        $this->applyEncoding($request, $options?->encodingOptions);

        return $this->egressInfoRpc('StartRoomCompositeEgress', $request);
    }

    /**
     * Splits a caller-supplied output into the plural arrays and, for a bare single output,
     * the deprecated singular oneof as well.
     *
     * @return array{
     *     legacyFile: ?EncodedFileOutput,
     *     legacyStream: ?StreamOutput,
     *     legacySegments: ?SegmentedFileOutput,
     *     fileOutputs: list<EncodedFileOutput>,
     *     streamOutputs: list<StreamOutput>,
     *     segmentOutputs: list<SegmentedFileOutput>,
     *     imageOutputs: list<ImageOutput>,
     * }
     */
    private function resolveOutputs(
        EncodedOutputs|EncodedFileOutput|StreamOutput|SegmentedFileOutput $output,
    ): array {
        $resolved = [
            'legacyFile' => null,
            'legacyStream' => null,
            'legacySegments' => null,
            'fileOutputs' => [],
            'streamOutputs' => [],
            'segmentOutputs' => [],
            'imageOutputs' => [],
        ];

        if ($output instanceof EncodedOutputs) {
            // Plural arrays only — the legacy oneof stays unset.
            if ($output->file !== null) {
                $resolved['fileOutputs'] = [$output->file];
            }

            if ($output->stream !== null) {
                $resolved['streamOutputs'] = [$output->stream];
            }

            if ($output->segments !== null) {
                $resolved['segmentOutputs'] = [$output->segments];
            }

            if ($output->images !== null) {
                $resolved['imageOutputs'] = [$output->images];
            }

            return $resolved;
        }

        // A bare single output fills both the plural array and the legacy oneof.
        if ($output instanceof EncodedFileOutput) {
            $resolved['legacyFile'] = $output;
            $resolved['fileOutputs'] = [$output];

            return $resolved;
        }

        if ($output instanceof SegmentedFileOutput) {
            $resolved['legacySegments'] = $output;
            $resolved['segmentOutputs'] = [$output];

            return $resolved;
        }

        $resolved['legacyStream'] = $output;
        $resolved['streamOutputs'] = [$output];

        return $resolved;
    }

    /**
     * @param array{
     *     legacyFile: ?EncodedFileOutput,
     *     legacyStream: ?StreamOutput,
     *     legacySegments: ?SegmentedFileOutput,
     *     fileOutputs: list<EncodedFileOutput>,
     *     streamOutputs: list<StreamOutput>,
     *     segmentOutputs: list<SegmentedFileOutput>,
     *     imageOutputs: list<ImageOutput>,
     * } $resolved
     */
    private function applyOutputArrays(
        RoomCompositeEgressRequest|WebEgressRequest|ParticipantEgressRequest|TrackCompositeEgressRequest $request,
        array $resolved,
    ): void {
        $request->setFileOutputs($resolved['fileOutputs']);
        $request->setStreamOutputs($resolved['streamOutputs']);
        $request->setSegmentOutputs($resolved['segmentOutputs']);
        $request->setImageOutputs($resolved['imageOutputs']);
    }

    /**
     * @param array{
     *     legacyFile: ?EncodedFileOutput,
     *     legacyStream: ?StreamOutput,
     *     legacySegments: ?SegmentedFileOutput,
     *     fileOutputs: list<EncodedFileOutput>,
     *     streamOutputs: list<StreamOutput>,
     *     segmentOutputs: list<SegmentedFileOutput>,
     *     imageOutputs: list<ImageOutput>,
     * } $resolved
     */
    private function applyLegacyOutput(
        RoomCompositeEgressRequest|WebEgressRequest|TrackCompositeEgressRequest $request,
        array $resolved,
    ): void {
        if ($resolved['legacyFile'] !== null) {
            $request->setFile($resolved['legacyFile']);

            return;
        }

        if ($resolved['legacyStream'] !== null) {
            $request->setStream($resolved['legacyStream']);

            return;
        }

        if ($resolved['legacySegments'] !== null) {
            $request->setSegments($resolved['legacySegments']);
        }
    }

    /**
     * The `options` oneof: an int selects a preset, an EncodingOptions message selects advanced.
     */
    private function applyEncoding(
        RoomCompositeEgressRequest|WebEgressRequest|ParticipantEgressRequest|TrackCompositeEgressRequest $request,
        int|EncodingOptions|null $encodingOptions,
    ): void {
        if ($encodingOptions instanceof EncodingOptions) {
            $request->setAdvanced($encodingOptions);

            return;
        }

        if ($encodingOptions !== null) {
            $request->setPreset($encodingOptions);
        }
    }

    private function egressInfoRpc(string $method, Message $request): EgressInfo
    {
        return $this->rpc(
            self::SERVICE,
            $method,
            $request,
            EgressInfo::class,
            $this->authHeader(new VideoGrant(roomRecord: true)),
        );
    }
}
