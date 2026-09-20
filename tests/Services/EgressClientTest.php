<?php

declare(strict_types=1);

namespace LiveKit\Tests\Services;

use LiveKit\Options\EncodedOutputs;
use LiveKit\Options\ParticipantEgressOptions;
use LiveKit\Options\RoomCompositeOptions;
use LiveKit\Options\TrackCompositeOptions;
use LiveKit\Options\WebOptions;
use LiveKit\Proto\DirectFileOutput;
use LiveKit\Proto\EgressInfo;
use LiveKit\Proto\EgressStatus;
use LiveKit\Proto\EncodedFileOutput;
use LiveKit\Proto\EncodedFileType;
use LiveKit\Proto\EncodingOptions;
use LiveKit\Proto\EncodingOptionsPreset;
use LiveKit\Proto\FileOutput;
use LiveKit\Proto\ImageOutput;
use LiveKit\Proto\Output;
use LiveKit\Proto\ParticipantEgressRequest;
use LiveKit\Proto\RoomCompositeEgressRequest;
use LiveKit\Proto\S3Upload;
use LiveKit\Proto\SegmentedFileOutput;
use LiveKit\Proto\StartEgressRequest;
use LiveKit\Proto\StorageConfig;
use LiveKit\Proto\StreamOutput;
use LiveKit\Proto\StreamProtocol;
use LiveKit\Proto\TemplateSource;
use LiveKit\Proto\TrackCompositeEgressRequest;
use LiveKit\Proto\TrackEgressRequest;
use LiveKit\Proto\UpdateLayoutRequest;
use LiveKit\Proto\UpdateStreamRequest;
use LiveKit\Proto\WebEgressRequest;
use LiveKit\Proto\WebhookConfig;
use LiveKit\Services\EgressClient;
use LiveKit\Tests\Support\TwirpTestCase;

final class EgressClientTest extends TwirpTestCase
{
    public function testStartRoomCompositeEgressWithEncodedOutputsSetsOnlyPluralArrays(): void
    {
        // The whole transport is the inherited mock: nothing leaves the process.
        $this->http->pushResponse($this->protoResponse($this->egressInfo('EG_composite')));

        $client = $this->egressClient();

        $file = new EncodedFileOutput();
        $file->setFileType(EncodedFileType::MP4);
        $file->setFilepath('recordings/room.mp4');

        $images = new ImageOutput();
        $images->setCaptureInterval(5);
        $images->setFilenamePrefix('thumb');

        $info = $client->startRoomCompositeEgress(
            'my-room',
            new EncodedOutputs(file: $file, images: $images),
            new RoomCompositeOptions(
                layout: 'speaker-dark',
                encodingOptions: EncodingOptionsPreset::H264_1080P_30,
                audioOnly: true,
            ),
        );

        self::assertSame('EG_composite', $info->getEgressId());
        self::assertSame(1, $this->http->requestCount());

        $sent = $this->http->lastRequest();
        $this->assertTwirpRequest($sent, 'Egress', 'StartRoomCompositeEgress');
        self::assertSame(self::HOST . '/twirp/livekit.Egress/StartRoomCompositeEgress', (string) $sent->getUri());

        $request = $this->decodeRequest(RoomCompositeEgressRequest::class);

        self::assertSame('my-room', $request->getRoomName());
        self::assertSame('speaker-dark', $request->getLayout());
        self::assertTrue($request->getAudioOnly());
        self::assertFalse($request->getVideoOnly());
        self::assertSame('', $request->getCustomBaseUrl());

        // EncodedOutputs path: plural arrays only, legacy oneof untouched.
        self::assertSame('', $request->getOutput());

        $fileOutputs = $this->messagesIn($request->getFileOutputs(), EncodedFileOutput::class);
        self::assertCount(1, $fileOutputs);
        self::assertSame('recordings/room.mp4', $fileOutputs[0]->getFilepath());
        self::assertSame(EncodedFileType::MP4, $fileOutputs[0]->getFileType());

        self::assertCount(0, $this->messagesIn($request->getStreamOutputs(), StreamOutput::class));
        self::assertCount(0, $this->messagesIn($request->getSegmentOutputs(), SegmentedFileOutput::class));

        $imageOutputs = $this->messagesIn($request->getImageOutputs(), ImageOutput::class);
        self::assertCount(1, $imageOutputs);
        self::assertSame('thumb', $imageOutputs[0]->getFilenamePrefix());
        self::assertSame(5, $imageOutputs[0]->getCaptureInterval());

        self::assertSame('preset', $request->getOptions());
        self::assertSame(EncodingOptionsPreset::H264_1080P_30, $request->getPreset());

        $this->assertVideoGrant(['roomRecord' => true], $sent);
    }

    public function testStartRoomCompositeEgressWithBareFileOutputAlsoSetsLegacyOneof(): void
    {
        $this->http->pushResponse($this->protoResponse($this->egressInfo('EG_legacy')));
        $client = $this->egressClient();

        $file = new EncodedFileOutput();
        $file->setFilepath('recordings/legacy.mp4');

        $info = $client->startRoomCompositeEgress('my-room', $file);

        self::assertSame('EG_legacy', $info->getEgressId());

        $sent = $this->http->lastRequest();
        $this->assertTwirpRequest($sent, 'Egress', 'StartRoomCompositeEgress');
        self::assertSame(self::HOST . '/twirp/livekit.Egress/StartRoomCompositeEgress', (string) $sent->getUri());

        $request = $this->decodeRequest(RoomCompositeEgressRequest::class);

        // Bare output path: BOTH the plural array and the deprecated oneof are set.
        self::assertSame('file', $request->getOutput());
        self::assertSame(
            'recordings/legacy.mp4',
            $this->messageOf($request->getFile(), EncodedFileOutput::class)->getFilepath(),
        );

        $fileOutputs = $this->messagesIn($request->getFileOutputs(), EncodedFileOutput::class);
        self::assertCount(1, $fileOutputs);
        self::assertSame('recordings/legacy.mp4', $fileOutputs[0]->getFilepath());

        // No options given -> the encoding oneof stays unset.
        self::assertSame('', $request->getOptions());
        self::assertSame('', $request->getLayout());

        $this->assertVideoGrant(['roomRecord' => true], $sent);
    }

    public function testStartWebEgressWithEncodedOutputsSetsOnlyPluralArrays(): void
    {
        $this->http->pushResponse($this->protoResponse($this->egressInfo('EG_web')));
        $client = $this->egressClient();

        $stream = new StreamOutput();
        $stream->setProtocol(StreamProtocol::RTMP);
        $stream->setUrls(['rtmp://one.example/live', 'rtmp://two.example/live']);

        $advanced = new EncodingOptions();
        $advanced->setWidth(1280);
        $advanced->setHeight(720);
        $advanced->setFramerate(30);

        $webhook = new WebhookConfig();
        $webhook->setUrl('https://hooks.example/egress');

        $info = $client->startWebEgress(
            'https://example.com/scene',
            new EncodedOutputs(stream: $stream),
            new WebOptions(
                encodingOptions: $advanced,
                videoOnly: true,
                awaitStartSignal: true,
                webhooks: [$webhook],
            ),
        );

        self::assertSame('EG_web', $info->getEgressId());

        $sent = $this->http->lastRequest();
        $this->assertTwirpRequest($sent, 'Egress', 'StartWebEgress');
        self::assertSame(self::HOST . '/twirp/livekit.Egress/StartWebEgress', (string) $sent->getUri());

        $request = $this->decodeRequest(WebEgressRequest::class);

        self::assertSame('https://example.com/scene', $request->getUrl());
        self::assertFalse($request->getAudioOnly());
        self::assertTrue($request->getVideoOnly());
        self::assertTrue($request->getAwaitStartSignal());

        self::assertSame('', $request->getOutput());

        $streamOutputs = $this->messagesIn($request->getStreamOutputs(), StreamOutput::class);
        self::assertCount(1, $streamOutputs);
        self::assertSame(StreamProtocol::RTMP, $streamOutputs[0]->getProtocol());
        self::assertSame(
            ['rtmp://one.example/live', 'rtmp://two.example/live'],
            $this->stringsIn($streamOutputs[0]->getUrls()),
        );

        self::assertSame('advanced', $request->getOptions());
        self::assertSame(1280, $this->messageOf($request->getAdvanced(), EncodingOptions::class)->getWidth());

        $webhooks = $this->messagesIn($request->getWebhooks(), WebhookConfig::class);
        self::assertCount(1, $webhooks);
        self::assertSame('https://hooks.example/egress', $webhooks[0]->getUrl());

        $this->assertVideoGrant(['roomRecord' => true], $sent);
    }

    public function testStartWebEgressWithBareSegmentsOutputAlsoSetsLegacyOneof(): void
    {
        $this->http->pushResponse($this->protoResponse($this->egressInfo('EG_web_legacy')));
        $client = $this->egressClient();

        $segments = new SegmentedFileOutput();
        $segments->setFilenamePrefix('hls/segment');
        $segments->setPlaylistName('hls/index.m3u8');
        $segments->setSegmentDuration(6);

        $info = $client->startWebEgress('https://example.com/scene', $segments);

        self::assertSame('EG_web_legacy', $info->getEgressId());

        $sent = $this->http->lastRequest();
        $this->assertTwirpRequest($sent, 'Egress', 'StartWebEgress');
        self::assertSame(self::HOST . '/twirp/livekit.Egress/StartWebEgress', (string) $sent->getUri());

        $request = $this->decodeRequest(WebEgressRequest::class);

        self::assertSame('segments', $request->getOutput());
        self::assertSame(
            'hls/index.m3u8',
            $this->messageOf($request->getSegments(), SegmentedFileOutput::class)->getPlaylistName(),
        );

        $segmentOutputs = $this->messagesIn($request->getSegmentOutputs(), SegmentedFileOutput::class);
        self::assertCount(1, $segmentOutputs);
        self::assertSame('hls/segment', $segmentOutputs[0]->getFilenamePrefix());

        self::assertSame('', $request->getOptions());
        self::assertCount(0, $this->messagesIn($request->getWebhooks(), WebhookConfig::class));

        $this->assertVideoGrant(['roomRecord' => true], $sent);
    }

    public function testStartParticipantEgressSetsOnlyPluralArrays(): void
    {
        $this->http->pushResponse($this->protoResponse($this->egressInfo('EG_participant')));
        $client = $this->egressClient();

        $file = new EncodedFileOutput();
        $file->setFilepath('participants/{publisher_identity}.mp4');

        $segments = new SegmentedFileOutput();
        $segments->setFilenamePrefix('participants/seg');

        $info = $client->startParticipantEgress(
            'my-room',
            'alice',
            new EncodedOutputs(file: $file, segments: $segments),
            new ParticipantEgressOptions(
                screenShare: true,
                encodingOptions: EncodingOptionsPreset::PORTRAIT_H264_720P_30,
            ),
        );

        self::assertSame('EG_participant', $info->getEgressId());

        $sent = $this->http->lastRequest();
        $this->assertTwirpRequest($sent, 'Egress', 'StartParticipantEgress');
        self::assertSame(self::HOST . '/twirp/livekit.Egress/StartParticipantEgress', (string) $sent->getUri());

        $request = $this->decodeRequest(ParticipantEgressRequest::class);

        self::assertSame('my-room', $request->getRoomName());
        self::assertSame('alice', $request->getIdentity());
        self::assertTrue($request->getScreenShare());

        $fileOutputs = $this->messagesIn($request->getFileOutputs(), EncodedFileOutput::class);
        self::assertCount(1, $fileOutputs);
        self::assertSame('participants/{publisher_identity}.mp4', $fileOutputs[0]->getFilepath());

        $segmentOutputs = $this->messagesIn($request->getSegmentOutputs(), SegmentedFileOutput::class);
        self::assertCount(1, $segmentOutputs);
        self::assertSame('participants/seg', $segmentOutputs[0]->getFilenamePrefix());

        self::assertCount(0, $this->messagesIn($request->getStreamOutputs(), StreamOutput::class));
        self::assertCount(0, $this->messagesIn($request->getImageOutputs(), ImageOutput::class));

        self::assertSame('preset', $request->getOptions());
        self::assertSame(EncodingOptionsPreset::PORTRAIT_H264_720P_30, $request->getPreset());

        $this->assertVideoGrant(['roomRecord' => true], $sent);
    }

    public function testStartParticipantEgressDefaultsScreenShareToFalseAndOmitsEncoding(): void
    {
        $this->http->pushResponse($this->protoResponse($this->egressInfo('EG_participant_defaults')));
        $client = $this->egressClient();

        $file = new EncodedFileOutput();
        $file->setFilepath('participants/bob.mp4');

        $client->startParticipantEgress('my-room', 'bob', new EncodedOutputs(file: $file));

        $sent = $this->http->lastRequest();
        $this->assertTwirpRequest($sent, 'Egress', 'StartParticipantEgress');
        self::assertSame(self::HOST . '/twirp/livekit.Egress/StartParticipantEgress', (string) $sent->getUri());

        $request = $this->decodeRequest(ParticipantEgressRequest::class);

        self::assertSame('bob', $request->getIdentity());
        self::assertFalse($request->getScreenShare());
        self::assertSame('', $request->getOptions());
        self::assertCount(0, $this->messagesIn($request->getWebhooks(), WebhookConfig::class));

        $this->assertVideoGrant(['roomRecord' => true], $sent);
    }

    public function testStartTrackCompositeEgressWithEncodedOutputsSetsOnlyPluralArrays(): void
    {
        $this->http->pushResponse($this->protoResponse($this->egressInfo('EG_track_composite')));
        $client = $this->egressClient();

        $stream = new StreamOutput();
        $stream->setProtocol(StreamProtocol::SRT);
        $stream->setUrls(['srt://stream.example:9000']);

        $info = $client->startTrackCompositeEgress(
            'my-room',
            new EncodedOutputs(stream: $stream),
            new TrackCompositeOptions(
                audioTrackId: 'TR_audio',
                videoTrackId: 'TR_video',
                encodingOptions: EncodingOptionsPreset::H264_720P_60,
            ),
        );

        self::assertSame('EG_track_composite', $info->getEgressId());

        $sent = $this->http->lastRequest();
        $this->assertTwirpRequest($sent, 'Egress', 'StartTrackCompositeEgress');
        self::assertSame(self::HOST . '/twirp/livekit.Egress/StartTrackCompositeEgress', (string) $sent->getUri());

        $request = $this->decodeRequest(TrackCompositeEgressRequest::class);

        self::assertSame('my-room', $request->getRoomName());
        self::assertSame('TR_audio', $request->getAudioTrackId());
        self::assertSame('TR_video', $request->getVideoTrackId());

        self::assertSame('', $request->getOutput());

        $streamOutputs = $this->messagesIn($request->getStreamOutputs(), StreamOutput::class);
        self::assertCount(1, $streamOutputs);
        self::assertSame(['srt://stream.example:9000'], $this->stringsIn($streamOutputs[0]->getUrls()));

        self::assertSame('preset', $request->getOptions());
        self::assertSame(EncodingOptionsPreset::H264_720P_60, $request->getPreset());

        $this->assertVideoGrant(['roomRecord' => true], $sent);
    }

    public function testStartTrackCompositeEgressWithBareStreamOutputAlsoSetsLegacyOneof(): void
    {
        $this->http->pushResponse($this->protoResponse($this->egressInfo('EG_track_composite_legacy')));
        $client = $this->egressClient();

        $stream = new StreamOutput();
        $stream->setProtocol(StreamProtocol::RTMP);
        $stream->setUrls(['rtmp://legacy.example/live']);

        $client->startTrackCompositeEgress('my-room', $stream);

        $sent = $this->http->lastRequest();
        $this->assertTwirpRequest($sent, 'Egress', 'StartTrackCompositeEgress');
        self::assertSame(self::HOST . '/twirp/livekit.Egress/StartTrackCompositeEgress', (string) $sent->getUri());

        $request = $this->decodeRequest(TrackCompositeEgressRequest::class);

        self::assertSame('stream', $request->getOutput());
        self::assertSame(
            ['rtmp://legacy.example/live'],
            $this->stringsIn($this->messageOf($request->getStream(), StreamOutput::class)->getUrls()),
        );

        $streamOutputs = $this->messagesIn($request->getStreamOutputs(), StreamOutput::class);
        self::assertCount(1, $streamOutputs);
        self::assertSame(StreamProtocol::RTMP, $streamOutputs[0]->getProtocol());

        self::assertSame('', $request->getAudioTrackId());
        self::assertSame('', $request->getVideoTrackId());
        self::assertSame('', $request->getOptions());

        $this->assertVideoGrant(['roomRecord' => true], $sent);
    }

    public function testStartTrackEgressWithDirectFileOutput(): void
    {
        $this->http->pushResponse($this->protoResponse($this->egressInfo('EG_track_file')));
        $client = $this->egressClient();

        $s3 = new S3Upload();
        $s3->setBucket('my-bucket');
        $s3->setRegion('eu-central-1');

        $file = new DirectFileOutput();
        $file->setFilepath('tracks/{track_id}.ogg');
        $file->setDisableManifest(true);
        $file->setS3($s3);

        $webhook = new WebhookConfig();
        $webhook->setUrl('https://hooks.example/track');

        $info = $client->startTrackEgress('my-room', $file, 'TR_audio', [$webhook]);

        self::assertSame('EG_track_file', $info->getEgressId());

        $sent = $this->http->lastRequest();
        $this->assertTwirpRequest($sent, 'Egress', 'StartTrackEgress');
        self::assertSame(self::HOST . '/twirp/livekit.Egress/StartTrackEgress', (string) $sent->getUri());

        $request = $this->decodeRequest(TrackEgressRequest::class);

        self::assertSame('my-room', $request->getRoomName());
        self::assertSame('TR_audio', $request->getTrackId());
        self::assertSame('file', $request->getOutput());

        $directFile = $this->messageOf($request->getFile(), DirectFileOutput::class);
        self::assertSame('tracks/{track_id}.ogg', $directFile->getFilepath());
        self::assertTrue($directFile->getDisableManifest());
        self::assertSame('my-bucket', $this->messageOf($directFile->getS3(), S3Upload::class)->getBucket());

        $webhooks = $this->messagesIn($request->getWebhooks(), WebhookConfig::class);
        self::assertCount(1, $webhooks);
        self::assertSame('https://hooks.example/track', $webhooks[0]->getUrl());

        $this->assertVideoGrant(['roomRecord' => true], $sent);
    }

    public function testStartTrackEgressWithWebsocketUrl(): void
    {
        $this->http->pushResponse($this->protoResponse($this->egressInfo('EG_track_ws')));
        $client = $this->egressClient();

        $client->startTrackEgress('my-room', 'wss://relay.example/track', 'TR_audio');

        $sent = $this->http->lastRequest();
        $this->assertTwirpRequest($sent, 'Egress', 'StartTrackEgress');
        self::assertSame(self::HOST . '/twirp/livekit.Egress/StartTrackEgress', (string) $sent->getUri());

        $request = $this->decodeRequest(TrackEgressRequest::class);

        self::assertSame('my-room', $request->getRoomName());
        self::assertSame('TR_audio', $request->getTrackId());
        self::assertSame('websocket_url', $request->getOutput());
        self::assertSame('wss://relay.example/track', $request->getWebsocketUrl());
        self::assertCount(0, $this->messagesIn($request->getWebhooks(), WebhookConfig::class));

        $this->assertVideoGrant(['roomRecord' => true], $sent);
    }

    public function testStartEgressSendsTheUnifiedRequestVerbatim(): void
    {
        $this->http->pushResponse($this->protoResponse($this->egressInfo('EG_unified')));
        $client = $this->egressClient();

        $template = new TemplateSource();
        $template->setLayout('grid-light');
        $template->setAudioOnly(true);

        $fileOutput = new FileOutput();
        $fileOutput->setFilepath('unified/room.mp4');

        $s3 = new S3Upload();
        $s3->setBucket('unified-bucket');

        $storage = new StorageConfig();
        $storage->setS3($s3);

        $output = new Output();
        $output->setFile($fileOutput);

        $request = new StartEgressRequest();
        $request->setRoomName('my-room');
        $request->setTemplate($template);
        $request->setPreset(EncodingOptionsPreset::H264_1080P_60);
        $request->setOutputs([$output]);
        $request->setStorage($storage);

        $info = $client->startEgress($request);

        self::assertSame('EG_unified', $info->getEgressId());

        $sent = $this->http->lastRequest();
        $this->assertTwirpRequest($sent, 'Egress', 'StartEgress');
        self::assertSame(self::HOST . '/twirp/livekit.Egress/StartEgress', (string) $sent->getUri());

        $decoded = $this->decodeRequest(StartEgressRequest::class);

        self::assertSame('my-room', $decoded->getRoomName());
        self::assertSame('template', $decoded->getSource());
        self::assertSame(
            'grid-light',
            $this->messageOf($decoded->getTemplate(), TemplateSource::class)->getLayout(),
        );
        self::assertSame('preset', $decoded->getEncoding());
        self::assertSame(EncodingOptionsPreset::H264_1080P_60, $decoded->getPreset());

        $outputs = $this->messagesIn($decoded->getOutputs(), Output::class);
        self::assertCount(1, $outputs);
        self::assertSame('file', $outputs[0]->getConfig());
        self::assertSame(
            'unified/room.mp4',
            $this->messageOf($outputs[0]->getFile(), FileOutput::class)->getFilepath(),
        );

        $decodedStorage = $this->messageOf($decoded->getStorage(), StorageConfig::class);
        self::assertSame('unified-bucket', $this->messageOf($decodedStorage->getS3(), S3Upload::class)->getBucket());

        $this->assertVideoGrant(['roomRecord' => true], $sent);
    }

    public function testUpdateLayout(): void
    {
        $this->http->pushResponse($this->protoResponse($this->egressInfo('EG_layout')));
        $client = $this->egressClient();

        $info = $client->updateLayout('EG_layout', 'grid-dark');

        self::assertSame('EG_layout', $info->getEgressId());

        $sent = $this->http->lastRequest();
        $this->assertTwirpRequest($sent, 'Egress', 'UpdateLayout');
        self::assertSame(self::HOST . '/twirp/livekit.Egress/UpdateLayout', (string) $sent->getUri());

        $request = $this->decodeRequest(UpdateLayoutRequest::class);

        self::assertSame('EG_layout', $request->getEgressId());
        self::assertSame('grid-dark', $request->getLayout());

        $this->assertVideoGrant(['roomRecord' => true], $sent);
    }

    public function testUpdateStreamSendsBothUrlLists(): void
    {
        $this->http->pushResponse($this->protoResponse($this->egressInfo('EG_stream')));
        $client = $this->egressClient();

        $info = $client->updateStream(
            'EG_stream',
            ['rtmp://add-one.example/live', 'rtmp://add-two.example/live'],
            ['rtmp://remove.example/live'],
        );

        self::assertSame('EG_stream', $info->getEgressId());

        $sent = $this->http->lastRequest();
        $this->assertTwirpRequest($sent, 'Egress', 'UpdateStream');
        self::assertSame(self::HOST . '/twirp/livekit.Egress/UpdateStream', (string) $sent->getUri());

        $request = $this->decodeRequest(UpdateStreamRequest::class);

        self::assertSame('EG_stream', $request->getEgressId());
        self::assertSame(
            ['rtmp://add-one.example/live', 'rtmp://add-two.example/live'],
            $this->stringsIn($request->getAddOutputUrls()),
        );
        self::assertSame(['rtmp://remove.example/live'], $this->stringsIn($request->getRemoveOutputUrls()));

        $this->assertVideoGrant(['roomRecord' => true], $sent);
    }

    public function testUpdateStreamDefaultsBothUrlListsToEmpty(): void
    {
        $this->http->pushResponse($this->protoResponse($this->egressInfo('EG_stream_empty')));
        $client = $this->egressClient();

        $client->updateStream('EG_stream_empty');

        $sent = $this->http->lastRequest();
        $this->assertTwirpRequest($sent, 'Egress', 'UpdateStream');
        self::assertSame(self::HOST . '/twirp/livekit.Egress/UpdateStream', (string) $sent->getUri());

        $request = $this->decodeRequest(UpdateStreamRequest::class);

        self::assertSame('EG_stream_empty', $request->getEgressId());
        self::assertSame([], $this->stringsIn($request->getAddOutputUrls()));
        self::assertSame([], $this->stringsIn($request->getRemoveOutputUrls()));

        $this->assertVideoGrant(['roomRecord' => true], $sent);
    }

    private function egressClient(): EgressClient
    {
        return new EgressClient(self::HOST, self::API_KEY, self::API_SECRET, httpClient: $this->http);
    }

    private function egressInfo(string $egressId = 'EG_test'): EgressInfo
    {
        $info = new EgressInfo();
        $info->setEgressId($egressId);
        $info->setStatus(EgressStatus::EGRESS_STARTING);

        return $info;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function messageOf(mixed $value, string $class): object
    {
        self::assertInstanceOf($class, $value);

        return $value;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return list<T>
     */
    private function messagesIn(mixed $repeated, string $class): array
    {
        self::assertIsIterable($repeated);
        /** @var iterable<mixed> $repeated */
        $items = [];

        foreach ($repeated as $item) {
            self::assertInstanceOf($class, $item);
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function stringsIn(mixed $repeated): array
    {
        self::assertIsIterable($repeated);
        /** @var iterable<mixed> $repeated */
        $items = [];

        foreach ($repeated as $item) {
            self::assertIsString($item);
            $items[] = $item;
        }

        return $items;
    }
}
