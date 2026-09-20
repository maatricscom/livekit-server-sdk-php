<?php

declare(strict_types=1);

namespace LiveKit\Tests;

use Firebase\JWT\JWT;
use LiveKit\AccessToken;
use LiveKit\AccessTokenOptions;
use LiveKit\Grants\VideoGrant;
use LiveKit\Tests\Support\TestCase;
use LiveKit\TokenVerifier;

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

        $this->expectException(\Firebase\JWT\SignatureInvalidException::class);

        (new TokenVerifier(self::API_KEY, 'a-completely-different-secret-value'))->verify($token->toJwt());
    }

    public function test_rejects_an_expired_token(): void
    {
        $now = time();
        $jwt = JWT::encode(
            ['iss' => self::API_KEY, 'iat' => $now - 7200, 'nbf' => $now - 7200, 'exp' => $now - 3600],
            self::API_SECRET,
            'HS256'
        );

        $this->expectException(\Firebase\JWT\ExpiredException::class);

        (new TokenVerifier(self::API_KEY, self::API_SECRET))->verify($jwt);
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

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Token issuer does not match the configured API key.');

        (new TokenVerifier(self::API_KEY, self::API_SECRET))->verify($jwt);
    }

    public function test_rejects_a_token_with_no_issuer_claim(): void
    {
        $jwt = JWT::encode(
            ['sub' => 'alice', 'exp' => time() + 3600],
            self::API_SECRET,
            'HS256'
        );

        $this->expectException(\UnexpectedValueException::class);

        (new TokenVerifier(self::API_KEY, self::API_SECRET))->verify($jwt);
    }
}
