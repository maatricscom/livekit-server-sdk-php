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
use LiveKit\Proto\ListEgressResponse;
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
     * One request, and the response the server built -- `next_page_token` included.
     * The same shape LiveKit's Go, Python and Ruby SDKs return from ListEgress, and
     * the one list call here that is not unwrapped to an array: an array cannot
     * carry a cursor, and dropping it is what leaves a caller holding a page they
     * cannot tell from the whole.
     */
    public function listEgress(?ListEgressOptions $options = null): ListEgressResponse;

    /**
     * Walks every page, asking for the next only once the caller has taken the
     * current one, so stopping early stops the requests too.
     *
     * @return \Generator<int, EgressInfo, mixed, void>
     */
    public function iterateEgress(?ListEgressOptions $options = null): \Generator;

    /**
     * Every page, collected into one array.
     *
     * @return list<EgressInfo>
     */
    public function listAllEgress(?ListEgressOptions $options = null): array;

    public function stopEgress(string $egressId): EgressInfo;
}
