<?php

declare(strict_types=1);

namespace LiveKit\Tests;

use LiveKit\AccessToken;
use LiveKit\AccessTokenOptions;
use LiveKit\Exceptions\ConfigurationException;
use LiveKit\Grants\SIPGrant;
use LiveKit\Grants\VideoGrant;
use LiveKit\Tests\Support\TestCase;

final class AccessTokenTest extends TestCase
{
    public function test_signs_with_hs256_and_sets_the_standard_claims(): void
    {
        $token = new AccessToken(self::API_KEY, self::API_SECRET, new AccessTokenOptions(identity: 'alice'));
        $token->addGrant(new VideoGrant(roomJoin: true, room: 'my-room'));

        $jwt = $token->toJwt();
        $claims = $this->decodeJwtPayload($jwt);

        $header = json_decode(
            (string) base64_decode(strtr(explode('.', $jwt)[0], '-_', '+/'), true),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertIsArray($header);

        /** @var array<string, mixed> $header */
        self::assertSame('HS256', $header['alg']);
        self::assertSame('JWT', $header['typ']);
        self::assertSame(self::API_KEY, $claims['iss']);
        self::assertSame('alice', $claims['sub']);
        self::assertArrayHasKey('iat', $claims);
        self::assertArrayHasKey('nbf', $claims);
        self::assertArrayHasKey('exp', $claims);
    }

    /**
     * Go's tokenClaims embeds RegisteredClaims and ClaimGrants side by side, so
     * the grant sits at the top level. A nested `grants` object would be rejected.
     */
    public function test_grants_are_flat_and_there_is_no_jti(): void
    {
        $token = new AccessToken(self::API_KEY, self::API_SECRET, new AccessTokenOptions(identity: 'alice'));
        $token->addGrant(new VideoGrant(roomJoin: true, room: 'my-room'));

        $claims = $this->decodeJwtPayload($token->toJwt());

        self::assertArrayHasKey('video', $claims);
        self::assertSame(['roomJoin' => true, 'room' => 'my-room'], $claims['video']);
        self::assertArrayNotHasKey('grants', $claims);
        self::assertArrayNotHasKey('jti', $claims);
    }

    public function test_default_ttl_is_six_hours(): void
    {
        $token = new AccessToken(self::API_KEY, self::API_SECRET, new AccessTokenOptions(identity: 'alice'));
        $token->addGrant(new VideoGrant(roomJoin: true, room: 'r'));

        $claims = $this->decodeJwtPayload($token->toJwt());

        $exp = $claims['exp'];
        $nbf = $claims['nbf'];
        self::assertIsInt($exp);
        self::assertIsInt($nbf);

        /**
         * @var int $exp
         * @var int $nbf
         */
        self::assertSame(21600, $exp - $nbf);
    }

    /**
     * @return array<string, array{int|string, int}>
     */
    public static function ttlProvider(): array
    {
        return [
            'seconds as int' => [600, 600],
            'seconds suffix' => ['45s', 45],
            'minutes suffix' => ['10m', 600],
            'hours suffix' => ['6h', 21600],
            'days suffix' => ['2d', 172800],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ttlProvider')]
    public function test_parses_ttl_in_both_forms(int|string $ttl, int $expected): void
    {
        self::assertSame($expected, AccessToken::parseTtl($ttl));
    }

    public function test_rejects_an_unparseable_ttl(): void
    {
        $this->expectException(ConfigurationException::class);

        AccessToken::parseTtl('a fortnight');
    }

    public function test_carries_sip_grants_alongside_video_grants(): void
    {
        $token = new AccessToken(self::API_KEY, self::API_SECRET);
        $token->addGrant(new VideoGrant(roomAdmin: true, room: 'r'))->addSipGrant(new SIPGrant(call: true));

        $claims = $this->decodeJwtPayload($token->toJwt());

        self::assertSame(['roomAdmin' => true, 'room' => 'r'], $claims['video']);
        self::assertSame(['call' => true], $claims['sip']);
    }

    public function test_requires_an_identity_when_the_grant_allows_joining(): void
    {
        $token = new AccessToken(self::API_KEY, self::API_SECRET);
        $token->addGrant(new VideoGrant(roomJoin: true, room: 'my-room'));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/identity/i');

        $token->toJwt();
    }

    public function test_does_not_require_an_identity_for_server_api_tokens(): void
    {
        $token = new AccessToken(self::API_KEY, self::API_SECRET);
        $token->addGrant(new VideoGrant(roomCreate: true));

        $claims = $this->decodeJwtPayload($token->toJwt());

        self::assertArrayNotHasKey('sub', $claims);
    }

    public function test_falls_back_to_environment_credentials(): void
    {
        putenv('LIVEKIT_API_KEY=env-key');
        putenv('LIVEKIT_API_SECRET=env-secret-that-is-long-enough-yes');

        try {
            $token = new AccessToken();
            $token->addGrant(new VideoGrant(roomList: true));

            self::assertSame('env-key', $this->decodeJwtPayload($token->toJwt())['iss']);
        } finally {
            putenv('LIVEKIT_API_KEY');
            putenv('LIVEKIT_API_SECRET');
        }
    }

    public function test_rejects_missing_credentials(): void
    {
        putenv('LIVEKIT_API_KEY');
        putenv('LIVEKIT_API_SECRET');

        $this->expectException(ConfigurationException::class);

        new AccessToken();
    }

    /**
     * firebase/php-jwt v7 throws a bare DomainException for short HMAC keys.
     * We convert it into an SDK error that names the requirement.
     */
    public function test_rejects_a_secret_shorter_than_32_bytes(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/at least 32 bytes/');

        new AccessToken('key', 'too-short');
    }

    public function test_carries_participant_metadata_and_attributes(): void
    {
        $token = new AccessToken(
            self::API_KEY,
            self::API_SECRET,
            new AccessTokenOptions(
                identity: 'alice',
                name: 'Alice',
                metadata: '{"tier":"pro"}',
                attributes: ['seat' => '3A'],
            )
        );
        $token->addGrant(new VideoGrant(roomJoin: true, room: 'r'));

        $claims = $this->decodeJwtPayload($token->toJwt());

        self::assertSame('Alice', $claims['name']);
        self::assertSame('{"tier":"pro"}', $claims['metadata']);
        self::assertSame(['seat' => '3A'], $claims['attributes']);
    }

    public function test_sets_the_sha256_claim_used_by_webhooks(): void
    {
        $token = new AccessToken(self::API_KEY, self::API_SECRET);
        $token->setSha256('Zm9vYmFy');

        self::assertSame('Zm9vYmFy', $this->decodeJwtPayload($token->toJwt())['sha256']);
    }

    /**
     * Asserts the LiveKit-specific claims match what the Go implementation emits
     * for the same inputs. Registered claims that depend on wall-clock time
     * (iat, nbf, exp) are compared as a duration, not as absolute values.
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function goldenProvider(): array
    {
        return [
            'join_room' => ['join_room', [
                'iss' => 'devkey',
                'sub' => 'alice',
                'name' => 'Alice',
                'video' => ['roomJoin' => true, 'room' => 'my-room'],
            ]],
            'publish_denied' => ['publish_denied', [
                'iss' => 'devkey',
                'sub' => 'bob',
                'video' => ['roomJoin' => true, 'room' => 'my-room', 'canPublish' => false],
            ]],
            'room_admin' => ['room_admin', [
                'iss' => 'devkey',
                'video' => ['roomAdmin' => true, 'room' => 'my-room'],
            ]],
            'sip_call' => ['sip_call', [
                'iss' => 'devkey',
                'sip' => ['call' => true],
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('goldenProvider')]
    public function test_matches_the_go_implementation(string $fixture, array $expected): void
    {
        $tokens = json_decode(
            (string) file_get_contents(__DIR__ . '/Fixtures/go-tokens.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertIsArray($tokens);

        /** @var array<string, string> $tokens */
        self::assertArrayHasKey($fixture, $tokens, 'Regenerate with bin/generate-jwt-fixtures.go');

        $claims = $this->decodeJwtPayload($tokens[$fixture]);

        foreach ($expected as $key => $value) {
            self::assertArrayHasKey($key, $claims, sprintf('Go token is missing "%s"', $key));
            self::assertSame($value, $claims[$key], sprintf('claim "%s" differs from Go', $key));
        }

        // The claim set itself must not be wider than what this SDK knows how to mint.
        $known = ['iss', 'sub', 'iat', 'nbf', 'exp', 'name', 'kind', 'kindDetails', 'video', 'sip',
            'agent', 'inference', 'observability', 'metadata', 'attributes', 'sha256', 'roomPreset',
            'roomConfig', 'identity'];

        self::assertSame([], array_diff(array_keys($claims), $known), 'Go emits a claim this SDK does not model');
    }
}
