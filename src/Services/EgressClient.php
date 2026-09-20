<?php

declare(strict_types=1);

namespace LiveKit\Services;

use Google\Protobuf\Internal\Message;
use LiveKit\Contracts\EgressClientInterface;
use LiveKit\Grants\VideoGrant;
use LiveKit\Options\EncodedOutputs;
use LiveKit\Options\ListEgressOptions;
use LiveKit\Options\ParticipantEgressOptions;
use LiveKit\Options\RoomCompositeOptions;
use LiveKit\Options\TrackCompositeOptions;
use LiveKit\Options\WebOptions;
use LiveKit\Proto\AudioMixing;
use LiveKit\Proto\DirectFileOutput;
use LiveKit\Proto\EgressInfo;
use LiveKit\Proto\EncodedFileOutput;
use LiveKit\Proto\EncodingOptions;
use LiveKit\Proto\ImageOutput;
use LiveKit\Proto\ListEgressRequest;
use LiveKit\Proto\ListEgressResponse;
use LiveKit\Proto\ParticipantEgressRequest;
use LiveKit\Proto\RoomCompositeEgressRequest;
use LiveKit\Proto\SegmentedFileOutput;
use LiveKit\Proto\StartEgressRequest;
use LiveKit\Proto\StopEgressRequest;
use LiveKit\Proto\StreamOutput;
use LiveKit\Proto\TrackCompositeEgressRequest;
use LiveKit\Proto\TrackEgressRequest;
use LiveKit\Proto\UpdateLayoutRequest;
use LiveKit\Proto\UpdateStreamRequest;
use LiveKit\Proto\WebEgressRequest;
use LiveKit\Proto\WebhookConfig;

/**
 * Client for the livekit.Egress Twirp service. Every rpc authenticates with roomRecord.
 */
final class EgressClient extends ServiceBase implements EgressClientInterface
{
    private const string SERVICE = 'Egress';

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

    public function startWebEgress(
        string $url,
        EncodedOutputs|EncodedFileOutput|StreamOutput|SegmentedFileOutput $output,
        ?WebOptions $options = null,
    ): EgressInfo {
        $resolved = $this->resolveOutputs($output);

        $request = new WebEgressRequest();
        $request->setUrl($url);
        $request->setAudioOnly($options->audioOnly ?? false);
        $request->setVideoOnly($options->videoOnly ?? false);
        $request->setAwaitStartSignal($options->awaitStartSignal ?? false);
        $request->setWebhooks($options->webhooks ?? []);

        $this->applyOutputArrays($request, $resolved);
        $this->applyLegacyOutput($request, $resolved);
        $this->applyEncoding($request, $options?->encodingOptions);

        return $this->egressInfoRpc('StartWebEgress', $request);
    }

    public function startParticipantEgress(
        string $roomName,
        string $identity,
        EncodedOutputs $output,
        ?ParticipantEgressOptions $options = null,
    ): EgressInfo {
        $resolved = $this->resolveOutputs($output);

        $request = new ParticipantEgressRequest();
        $request->setRoomName($roomName);
        $request->setIdentity($identity);
        $request->setScreenShare($options->screenShare ?? false);
        $request->setWebhooks($options->webhooks ?? []);

        // ParticipantEgressRequest has no legacy `output` oneof, so only the plural arrays are set.
        $this->applyOutputArrays($request, $resolved);
        $this->applyEncoding($request, $options?->encodingOptions);

        return $this->egressInfoRpc('StartParticipantEgress', $request);
    }

    public function startTrackCompositeEgress(
        string $roomName,
        EncodedOutputs|EncodedFileOutput|StreamOutput|SegmentedFileOutput $output,
        ?TrackCompositeOptions $options = null,
    ): EgressInfo {
        $resolved = $this->resolveOutputs($output);

        $request = new TrackCompositeEgressRequest();
        $request->setRoomName($roomName);
        $request->setAudioTrackId($options->audioTrackId ?? '');
        $request->setVideoTrackId($options->videoTrackId ?? '');
        $request->setWebhooks($options->webhooks ?? []);

        $this->applyOutputArrays($request, $resolved);
        $this->applyLegacyOutput($request, $resolved);
        $this->applyEncoding($request, $options?->encodingOptions);

        return $this->egressInfoRpc('StartTrackCompositeEgress', $request);
    }

    /**
     * @param list<WebhookConfig>|null $webhooks
     */
    public function startTrackEgress(
        string $roomName,
        DirectFileOutput|string $output,
        string $trackId,
        ?array $webhooks = null,
    ): EgressInfo {
        $request = new TrackEgressRequest();
        $request->setRoomName($roomName);
        $request->setTrackId($trackId);
        $request->setWebhooks($webhooks ?? []);

        if ($output instanceof DirectFileOutput) {
            $request->setFile($output);
        } else {
            $request->setWebsocketUrl($output);
        }

        return $this->egressInfoRpc('StartTrackEgress', $request);
    }

    public function startEgress(StartEgressRequest $request): EgressInfo
    {
        return $this->egressInfoRpc('StartEgress', $request);
    }

    public function updateLayout(string $egressId, string $layout): EgressInfo
    {
        $request = new UpdateLayoutRequest();
        $request->setEgressId($egressId);
        $request->setLayout($layout);

        return $this->egressInfoRpc('UpdateLayout', $request);
    }

    /**
     * @param list<string>|null $addOutputUrls
     * @param list<string>|null $removeOutputUrls
     */
    public function updateStream(
        string $egressId,
        ?array $addOutputUrls = null,
        ?array $removeOutputUrls = null,
    ): EgressInfo {
        $request = new UpdateStreamRequest();
        $request->setEgressId($egressId);
        $request->setAddOutputUrls($addOutputUrls ?? []);
        $request->setRemoveOutputUrls($removeOutputUrls ?? []);

        return $this->egressInfoRpc('UpdateStream', $request);
    }

    /**
     * @return list<EgressInfo>
     */
    public function listEgress(?ListEgressOptions $options = null): array
    {
        $request = new ListEgressRequest();

        if ($options?->roomName !== null) {
            $request->setRoomName($options->roomName);
        }

        if ($options?->egressId !== null) {
            $request->setEgressId($options->egressId);
        }

        if ($options?->active !== null) {
            $request->setActive($options->active);
        }

        $response = $this->rpc(
            self::SERVICE,
            'ListEgress',
            $request,
            ListEgressResponse::class,
            $this->authHeader(new VideoGrant(roomRecord: true)),
        );

        $items = [];

        foreach ($response->getItems() as $item) {
            $items[] = $item;
        }

        return $items;
    }

    public function stopEgress(string $egressId): EgressInfo
    {
        $request = new StopEgressRequest();
        $request->setEgressId($egressId);

        return $this->egressInfoRpc('StopEgress', $request);
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
