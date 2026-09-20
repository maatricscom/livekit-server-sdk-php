<?php

declare(strict_types=1);

namespace LiveKit\Tests\Services;

use LiveKit\Options\EncodedOutputs;
use LiveKit\Options\RoomCompositeOptions;
use LiveKit\Proto\EgressInfo;
use LiveKit\Proto\EgressStatus;
use LiveKit\Proto\EncodedFileOutput;
use LiveKit\Proto\EncodedFileType;
use LiveKit\Proto\EncodingOptionsPreset;
use LiveKit\Proto\ImageOutput;
use LiveKit\Proto\RoomCompositeEgressRequest;
use LiveKit\Proto\SegmentedFileOutput;
use LiveKit\Proto\StreamOutput;
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
}
