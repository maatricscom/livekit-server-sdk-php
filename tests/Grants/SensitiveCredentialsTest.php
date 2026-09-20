<?php

declare(strict_types=1);

namespace LiveKit\Tests\Grants;

use LiveKit\AccessToken;
use LiveKit\Exceptions\ConfigurationException;
use LiveKit\Grants\SensitiveCredentials;
use LiveKit\Grants\VideoGrant;
use LiveKit\Options\AccessTokenOptions;
use LiveKit\Proto\AliOSSUpload;
use LiveKit\Proto\AutoParticipantEgress;
use LiveKit\Proto\AutoTrackEgress;
use LiveKit\Proto\AzureBlobUpload;
use LiveKit\Proto\EncodedFileOutput;
use LiveKit\Proto\GCPUpload;
use LiveKit\Proto\ImageOutput;
use LiveKit\Proto\RoomCompositeEgressRequest;
use LiveKit\Proto\RoomConfiguration;
use LiveKit\Proto\RoomEgress;
use LiveKit\Proto\S3Upload;
use LiveKit\Proto\SegmentedFileOutput;
use LiveKit\Proto\StreamOutput;
use LiveKit\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A room configuration rides inside the access token, and a JWT is signed rather
 * than encrypted: whoever holds the token can read every byte of it. So an egress
 * configuration carrying an S3 secret, a GCP service account, an Azure account key
 * or a stream key is not kept on the server — it is handed to the participant.
 *
 * These tests are the reason the check exists, one per way a credential can get in.
 */
final class SensitiveCredentialsTest extends TestCase
{
    /** @return iterable<string, array{RoomConfiguration}> */
    public static function configsThatLeak(): iterable
    {
        $withEgress = static fn (RoomEgress $e): RoomConfiguration => (new RoomConfiguration())->setEgress($e);

        $s3 = static fn (): S3Upload => (new S3Upload())->setBucket('b')->setAccessKey('k')->setSecret('SECRET');

        yield 'room file output with an S3 secret' => [$withEgress(
            (new RoomEgress())->setRoom((new RoomCompositeEgressRequest())->setFileOutputs([
                (new EncodedFileOutput())->setFilepath('f')->setS3($s3()),
            ]))
        )];

        yield 'room segment output with GCP credentials' => [$withEgress(
            (new RoomEgress())->setRoom((new RoomCompositeEgressRequest())->setSegmentOutputs([
                (new SegmentedFileOutput())->setGcp((new GCPUpload())->setCredentials('{"private_key":"..."}')),
            ]))
        )];

        yield 'room image output with an Azure account key' => [$withEgress(
            (new RoomEgress())->setRoom((new RoomCompositeEgressRequest())->setImageOutputs([
                (new ImageOutput())->setAzure((new AzureBlobUpload())->setAccountKey('KEY')),
            ]))
        )];

        yield 'any stream output at all' => [$withEgress(
            (new RoomEgress())->setRoom((new RoomCompositeEgressRequest())->setStreamOutputs([
                (new StreamOutput())->setUrls(['rtmp://example.com/live/STREAMKEY']),
            ]))
        )];

        yield 'participant file output with an AliOSS secret' => [$withEgress(
            (new RoomEgress())->setParticipant((new AutoParticipantEgress())->setFileOutputs([
                (new EncodedFileOutput())->setAliOSS((new AliOSSUpload())->setSecret('SECRET')),
            ]))
        )];

        yield 'participant segment output with an S3 secret' => [$withEgress(
            (new RoomEgress())->setParticipant((new AutoParticipantEgress())->setSegmentOutputs([
                (new SegmentedFileOutput())->setS3($s3()),
            ]))
        )];

        yield 'track egress with an S3 secret' => [$withEgress(
            (new RoomEgress())->setTracks((new AutoTrackEgress())->setFilepath('f')->setS3($s3()))
        )];
    }

    #[DataProvider('configsThatLeak')]
    public function test_a_configuration_carrying_a_credential_is_detected(RoomConfiguration $config): void
    {
        self::assertTrue(SensitiveCredentials::presentIn($config));
    }

    #[DataProvider('configsThatLeak')]
    public function test_such_a_token_is_refused_rather_than_signed(RoomConfiguration $config): void
    {
        $token = new AccessToken(self::API_KEY, self::API_SECRET, new AccessTokenOptions(
            identity: 'alice',
            roomConfig: $config,
        ));
        $token->addGrant(new VideoGrant(roomJoin: true, room: 'r'));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/signed, not encrypted/');

        $token->toJwt();
    }

    #[DataProvider('configsThatLeak')]
    public function test_the_opt_out_is_there_for_a_token_that_really_does_stay_private(RoomConfiguration $config): void
    {
        $token = new AccessToken(self::API_KEY, self::API_SECRET, new AccessTokenOptions(
            identity: 'alice',
            roomConfig: $config,
        ));
        $token->addGrant(new VideoGrant(roomJoin: true, room: 'r'));

        self::assertNotSame('', $token->allowSensitiveCredentials()->toJwt());
    }

    /** @return iterable<string, array{RoomConfiguration}> */
    public static function configsThatAreSafe(): iterable
    {
        yield 'no egress at all' => [(new RoomConfiguration())->setName('r')];

        // A bucket reached by an instance role carries no secret to leak.
        yield 'S3 without a secret' => [(new RoomConfiguration())->setEgress(
            (new RoomEgress())->setRoom((new RoomCompositeEgressRequest())->setFileOutputs([
                (new EncodedFileOutput())->setS3((new S3Upload())->setBucket('b')->setRegion('eu-central-1')),
            ]))
        )];

        yield 'agents, timeouts and metadata' => [(new RoomConfiguration())
            ->setName('r')
            ->setEmptyTimeout(300)
            ->setMetadata('{"tier":"gold"}'), ];
    }

    #[DataProvider('configsThatAreSafe')]
    public function test_a_configuration_without_credentials_signs_normally(RoomConfiguration $config): void
    {
        self::assertFalse(SensitiveCredentials::presentIn($config));

        $token = new AccessToken(self::API_KEY, self::API_SECRET, new AccessTokenOptions(
            identity: 'alice',
            roomConfig: $config,
        ));
        $token->addGrant(new VideoGrant(roomJoin: true, room: 'r'));

        self::assertNotSame('', $token->toJwt());
    }

    public function test_no_room_configuration_is_nothing_to_check(): void
    {
        self::assertFalse(SensitiveCredentials::presentIn(null));
    }
}
