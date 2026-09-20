<?php

declare(strict_types=1);

namespace LiveKit\Contracts;

use LiveKit\Options\EncodedOutputs;
use LiveKit\Options\ListEgressOptions;
use LiveKit\Options\ParticipantEgressOptions;
use LiveKit\Options\RoomCompositeOptions;
use LiveKit\Options\TrackCompositeOptions;
use LiveKit\Options\WebOptions;
use LiveKit\Proto\DirectFileOutput;
use LiveKit\Proto\EgressInfo;
use LiveKit\Proto\EncodedFileOutput;
use LiveKit\Proto\SegmentedFileOutput;
use LiveKit\Proto\StartEgressRequest;
use LiveKit\Proto\StreamOutput;
use LiveKit\Proto\WebhookConfig;

interface EgressClientInterface
{
    public function startRoomCompositeEgress(
        string $roomName,
        EncodedOutputs|EncodedFileOutput|StreamOutput|SegmentedFileOutput $output,
        ?RoomCompositeOptions $options = null,
    ): EgressInfo;

    public function startWebEgress(
        string $url,
        EncodedOutputs|EncodedFileOutput|StreamOutput|SegmentedFileOutput $output,
        ?WebOptions $options = null,
    ): EgressInfo;

    public function startParticipantEgress(
        string $roomName,
        string $identity,
        EncodedOutputs $output,
        ?ParticipantEgressOptions $options = null,
    ): EgressInfo;

    public function startTrackCompositeEgress(
        string $roomName,
        EncodedOutputs|EncodedFileOutput|StreamOutput|SegmentedFileOutput $output,
        ?TrackCompositeOptions $options = null,
    ): EgressInfo;

    /**
     * @param list<WebhookConfig>|null $webhooks
     */
    public function startTrackEgress(
        string $roomName,
        DirectFileOutput|string $output,
        string $trackId,
        ?array $webhooks = null,
    ): EgressInfo;

    public function startEgress(StartEgressRequest $request): EgressInfo;

    public function updateLayout(string $egressId, string $layout): EgressInfo;

    /**
     * @param list<string>|null $addOutputUrls
     * @param list<string>|null $removeOutputUrls
     */
    public function updateStream(
        string $egressId,
        ?array $addOutputUrls = null,
        ?array $removeOutputUrls = null,
    ): EgressInfo;

    /**
     * @return list<EgressInfo>
     */
    public function listEgress(?ListEgressOptions $options = null): array;

    public function stopEgress(string $egressId): EgressInfo;
}
