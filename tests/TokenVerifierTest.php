<?php

declare(strict_types=1);

namespace LiveKit\Tests;

use Firebase\JWT\JWT;
use LiveKit\AccessToken;
use LiveKit\Exceptions\LiveKitException;
use LiveKit\Exceptions\TokenVerificationException;
use LiveKit\Grants\VideoGrant;
use LiveKit\Options\AccessTokenOptions;
use LiveKit\Tests\Support\TestCase;
use LiveKit\TokenVerifier;
use PHPUnit\Framework\Attributes\DataProvider;

final class TokenVerifierTest extends TestCase
{
    public function test_verifies_a_token_it_minted_and_returns_the_claims(): void
    {
        $token = new AccessToken(self::API_KEY, self::API_SECRET, new AccessTokenOptions(identity: 'alice'));
        $token->addGrant(new VideoGrant(roomJoin: true, room: 'my-room'));

        $claims = (new TokenVerifier(self::API_KEY, self::API_SECRET))->verify($token->toJwt());

        self::assertSame(self::API_KEY, $claims['iss']);
        self::assertSame('alice', $claims['sub']);
        self::assertSame(['roomJoin' => true, 'room' => 'my-room'], $claims['video']);
    }

    public function test_rejects_a_token_signed_with_a_different_secret(): void
    {
        $token = new AccessToken(self::API_KEY, self::API_SECRET);
        $token->addGrant(new VideoGrant(roomList: true));

        try {
            (new TokenVerifier(self::API_KEY, 'a-completely-different-secret-value'))->verify($token->toJwt());
            self::fail('Expected the forged token to be rejected');
        } catch (TokenVerificationException $e) {
            // Wrapped, not replaced: a caller that wants to tell a forged token from
            // an expired one still can.
            self::assertInstanceOf(\Firebase\JWT\SignatureInvalidException::class, $e->getPrevious());
        }
    }

    public function test_rejects_an_expired_token(): void
    {
        $now = time();
        $jwt = JWT::encode(
            ['iss' => self::API_KEY, 'iat' => $now - 7200, 'nbf' => $now - 7200, 'exp' => $now - 3600],
            self::API_SECRET,
            'HS256'
        );

        try {
            (new TokenVerifier(self::API_KEY, self::API_SECRET))->verify($jwt);
            self::fail('Expected the expired token to be rejected');
        } catch (TokenVerificationException $e) {
            self::assertInstanceOf(\Firebase\JWT\ExpiredException::class, $e->getPrevious());
        }
    }

    public function test_tolerates_clock_skew_within_the_allowance(): void
    {
        $now = time();
        $jwt = JWT::encode(
            ['iss' => self::API_KEY, 'iat' => $now, 'nbf' => $now + 30, 'exp' => $now + 3600],
            self::API_SECRET,
            'HS256'
        );

        $claims = (new TokenVerifier(self::API_KEY, self::API_SECRET))->verify($jwt, clockToleranceSeconds: 60);

        self::assertSame(self::API_KEY, $claims['iss']);
    }

    /**
     * Signature alone is not enough: a token correctly signed with this secret but
     * minted for a different API key must still be rejected, mirroring Node's
     * TokenVerifier passing `issuer: this.apiKey` to its JWT library.
     */
    public function test_rejects_a_token_whose_issuer_does_not_match_the_configured_api_key(): void
    {
        $jwt = JWT::encode(
            ['iss' => 'a-different-api-key', 'sub' => 'alice', 'exp' => time() + 3600],
            self::API_SECRET,
            'HS256'
        );

        try {
            (new TokenVerifier(self::API_KEY, self::API_SECRET))->verify($jwt);
            self::fail('Expected the mismatched issuer to be rejected');
        } catch (TokenVerificationException $e) {
            // The signature passed before this check, so the token was signed with
            // our own secret: this names a configuration mismatch, not an attack.
            self::assertStringContainsString('a-different-api-key', $e->getMessage());
            self::assertStringContainsString(self::API_KEY, $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function test_rejects_a_token_with_no_issuer_claim(): void
    {
        $jwt = JWT::encode(
            ['sub' => 'alice', 'exp' => time() + 3600],
            self::API_SECRET,
            'HS256'
        );

        $this->expectException(TokenVerificationException::class);

        (new TokenVerifier(self::API_KEY, self::API_SECRET))->verify($jwt);
    }

    /** @return iterable<string, array{string}> */
    public static function tokensThatCannotBeAccepted(): iterable
    {
        yield 'not a JWT at all' => ['not.a.jwt'];
        yield 'empty' => [''];
        yield 'two segments' => ['aaaa.bbbb'];
        yield 'a tampered signature' => [
            substr(JWT::encode(['iss' => self::API_KEY, 'exp' => time() + 3600], self::API_SECRET, 'HS256'), 0, -3) . 'AAA',
        ];
    }

    /**
     * Every way verification can fail is a LiveKitException. Before this, each path
     * raised something from firebase/php-jwt or a bare UnexpectedValueException, so
     * catching LiveKitException -- which this package tells people is enough --
     * caught none of them.
     */
    #[DataProvider('tokensThatCannotBeAccepted')]
    public function test_every_rejection_is_a_livekit_exception(string $jwt): void
    {
        try {
            (new TokenVerifier(self::API_KEY, self::API_SECRET))->verify($jwt);
            self::fail('Expected the token to be rejected');
        } catch (\Throwable $e) {
            self::assertInstanceOf(LiveKitException::class, $e);
            self::assertInstanceOf(TokenVerificationException::class, $e);
        }
    }

    public function test_the_verifier_reports_which_api_key_it_resolved(): void
    {
        // Worth having because the constructor falls back to the environment: this
        // is how a caller sees which key it actually ended up bound to.
        $this->withEnv(['LIVEKIT_API_KEY' => 'env-key', 'LIVEKIT_API_SECRET' => self::API_SECRET], function (): void {
            self::assertSame('env-key', (new TokenVerifier())->getApiKey());
        });

        self::assertSame(self::API_KEY, (new TokenVerifier(self::API_KEY, self::API_SECRET))->getApiKey());
    }

    /** @return iterable<string, array{string}> */
    public static function otherHmacAlgorithms(): iterable
    {
        yield 'HS384' => ['HS384'];
        yield 'HS512' => ['HS512'];
    }

    /**
     * The verifier is handed one algorithm, not the one the token names for itself.
     *
     * A token signed with the same secret under HS384 or HS512 is the discriminating
     * case: it is a genuine signature, so a verifier that read `alg` from the header
     * would accept it. `alg: none` is not a useful test here — firebase/php-jwt
     * refuses that on its own, so it passes whether or not this SDK pins anything.
     */
    #[DataProvider('otherHmacAlgorithms')]
    public function test_only_hs256_is_accepted_however_the_token_is_signed(string $alg): void
    {
        // Long enough for HS512 to sign with: firebase/php-jwt requires a key at
        // least as long as the digest, and the point here is a genuine signature.
        $secret = str_repeat('k', 64);

        $jwt = JWT::encode(
            ['iss' => self::API_KEY, 'exp' => time() + 3600, 'video' => ['roomAdmin' => true]],
            $secret,
            $alg
        );

        // Same secret, real signature, wrong algorithm. A verifier reading `alg`
        // from the header would accept this; one handed HS256 does not.
        $this->expectException(TokenVerificationException::class);

        (new TokenVerifier(self::API_KEY, $secret))->verify($jwt);
    }

    public function test_a_token_claiming_no_algorithm_is_rejected(): void
    {
        $header = rtrim(strtr(base64_encode('{"typ":"JWT","alg":"none"}'), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode([
            'iss' => self::API_KEY,
            'exp' => time() + 3600,
            'video' => ['roomAdmin' => true],
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        $this->expectException(TokenVerificationException::class);

        (new TokenVerifier(self::API_KEY, self::API_SECRET))->verify($header . '.' . $payload . '.');
    }
}
