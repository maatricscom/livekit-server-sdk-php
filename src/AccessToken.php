<?php

declare(strict_types=1);

namespace LiveKit;

use Firebase\JWT\JWT;
use LiveKit\Exceptions\ConfigurationException;
use LiveKit\Grants\AgentGrant;
use LiveKit\Grants\ClaimGrants;
use LiveKit\Grants\InferenceGrant;
use LiveKit\Grants\ObservabilityGrant;
use LiveKit\Grants\SensitiveCredentials;
use LiveKit\Grants\SIPGrant;
use LiveKit\Grants\VideoGrant;
use LiveKit\Options\AccessTokenOptions;
use LiveKit\Proto\RoomConfiguration;

/**
 * Mints the HS256 access tokens LiveKit accepts.
 *
 * The payload is flat: registered claims (iss, sub, iat, nbf, exp) and the grant
 * keys (video, sip, agent, ...) sit at the same level, mirroring how Go embeds
 * jwt.RegisteredClaims and ClaimGrants into one struct. There is no `jti`.
 */
final class AccessToken
{
    /** Matches Go's defaultValidDuration of 6 hours. */
    public const DEFAULT_TTL_SECONDS = 21600;

    /** firebase/php-jwt rejects HMAC keys shorter than this. */
    private const MIN_SECRET_BYTES = 32;

    private readonly string $apiKey;

    private readonly string $apiSecret;

    private readonly ClaimGrants $grants;

    private int|string $ttl;

    private bool $allowSensitiveCredentials = false;

    public function __construct(
        ?string $apiKey = null,
        ?string $apiSecret = null,
        ?AccessTokenOptions $options = null,
    ) {
        $apiKey ??= self::env('LIVEKIT_API_KEY');
        $apiSecret ??= self::env('LIVEKIT_API_SECRET');

        if ($apiKey === null || $apiKey === '' || $apiSecret === null || $apiSecret === '') {
            throw ConfigurationException::missingCredentials();
        }

        if (strlen($apiSecret) < self::MIN_SECRET_BYTES) {
            throw ConfigurationException::secretTooShort(strlen($apiSecret));
        }

        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;

        $options ??= new AccessTokenOptions();
        $this->ttl = $options->ttl;

        $this->grants = (new ClaimGrants())
            ->setIdentity($options->identity)
            ->setName($options->name)
            ->setKind($options->kind)
            ->setKindDetails($options->kindDetails)
            ->setMetadata($options->metadata)
            ->setAttributes($options->attributes)
            ->setRoomPreset($options->roomPreset)
            ->setRoomConfig($options->roomConfig);
    }

    /**
     * Allows this token to carry storage credentials in its room configuration.
     *
     * Off by default, and worth leaving off. A JWT is signed, not encrypted, so
     * everything in it is readable by whoever holds it: an S3 secret or a stream
     * key in the room configuration is published to that participant. LiveKit's Go
     * SDK refuses such a token the same way, and for the same reason.
     */
    public function allowSensitiveCredentials(bool $allow = true): self
    {
        $this->allowSensitiveCredentials = $allow;

        return $this;
    }

    public function setRoomConfig(?RoomConfiguration $roomConfig): self
    {
        $this->grants->setRoomConfig($roomConfig);

        return $this;
    }

    public function addGrant(VideoGrant $grant): self
    {
        $this->grants->setVideo($grant);

        return $this;
    }

    public function addSipGrant(SIPGrant $grant): self
    {
        $this->grants->setSip($grant);

        return $this;
    }

    public function addAgentGrant(AgentGrant $grant): self
    {
        $this->grants->setAgent($grant);

        return $this;
    }

    public function addInferenceGrant(InferenceGrant $grant): self
    {
        $this->grants->setInference($grant);

        return $this;
    }

    public function addObservabilityGrant(ObservabilityGrant $grant): self
    {
        $this->grants->setObservability($grant);

        return $this;
    }

    public function setIdentity(string $identity): self
    {
        $this->grants->setIdentity($identity);

        return $this;
    }

    public function setSha256(string $sha256): self
    {
        $this->grants->setSha256($sha256);

        return $this;
    }

    public function setTtl(int|string $ttl): self
    {
        $this->ttl = $ttl;

        return $this;
    }

    public function toJwt(): string
    {
        $grantClaims = $this->grants->toArray();

        // ClaimGrants::toArray() mirrors Go's ClaimGrants struct faithfully, which
        // includes a redundant `identity` field alongside the registered `sub` claim
        // set below. The server takes identity from `sub` (see verifier.go), so this
        // SDK deliberately does not send the duplicate.
        unset($grantClaims['identity']);

        $video = $this->grants->getVideo();
        $identity = $this->grants->getIdentity();

        if ($video !== null && $video->roomJoin && ($identity === null || $identity === '')) {
            throw new ConfigurationException(
                'A token granting roomJoin must carry an identity. '
                . 'Pass it via AccessTokenOptions(identity: ...) or setIdentity().'
            );
        }

        if (!$this->allowSensitiveCredentials && SensitiveCredentials::presentIn($this->grants->getRoomConfig())) {
            throw ConfigurationException::sensitiveCredentialsInRoomConfig();
        }

        $now = time();

        $claims = [
            'iss' => $this->apiKey,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + self::parseTtl($this->ttl),
        ];

        if ($identity !== null && $identity !== '') {
            $claims['sub'] = $identity;
        }

        // The grant keys are merged at the top level, not nested.
        return JWT::encode(array_merge($claims, $grantClaims), $this->apiSecret, 'HS256');
    }

    /**
     * Accepts seconds as an int, or a duration string with an s/m/h/d suffix.
     */
    public static function parseTtl(int|string $ttl): int
    {
        if (is_int($ttl)) {
            $seconds = $ttl;
        } else {
            if (preg_match('/^(\d+)([smhd])$/', trim($ttl), $matches) !== 1) {
                throw new ConfigurationException(sprintf(
                    'Could not parse the token TTL "%s". Use seconds as an integer, '
                    . 'or a duration string such as "45s", "10m", "6h" or "2d".',
                    $ttl
                ));
            }

            $value = (int) $matches[1];

            $seconds = match ($matches[2]) {
                's' => $value,
                'm' => $value * 60,
                'h' => $value * 3600,
                'd' => $value * 86400,
            };
        }

        // A duration string parses to a well-formed integer even at zero (e.g. "0s",
        // "0h"), which would otherwise mint a token with exp == nbf: instantly
        // expired, with no error raised anywhere. Both input forms share this check.
        if ($seconds <= 0) {
            throw new ConfigurationException('Token TTL must be a positive number of seconds.');
        }

        return $seconds;
    }

    /**
     * getenv() returns false rather than null when unset, so `??` never fires on it.
     */
    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
