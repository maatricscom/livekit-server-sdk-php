<?php

declare(strict_types=1);

namespace LiveKit\Services;

use Google\Protobuf\Internal\Message;
use LiveKit\AccessToken;
use LiveKit\Exceptions\ConfigurationException;
use LiveKit\Grants\SIPGrant;
use LiveKit\Grants\VideoGrant;
use LiveKit\Http\TwirpClient;
use LiveKit\Options\AccessTokenOptions;
use LiveKit\Options\ClientOptions;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Shared behaviour for every LiveKit service client: credential handling,
 * per-call token minting, and access to the Twirp transport.
 *
 * Each concrete client declares the grant its methods need, mirroring how the
 * Node SDK's ServiceBase.authHeader() is called per method rather than once
 * per client.
 */
abstract class ServiceBase
{
    /** Per-API-call tokens are short-lived; this matches the Node SDK. */
    public const SERVICE_TOKEN_TTL_SECONDS = 600;

    private readonly ?string $apiKey;

    private readonly ?string $apiSecret;

    /**
     * The token this client actually authenticates with, which is not the same
     * thing as ClientOptions::$token — that records what the caller configured,
     * while this may have come from LIVEKIT_TOKEN.
     */
    private readonly ?string $token;

    protected readonly ClientOptions $options;

    protected readonly TwirpClient $transport;

    public function __construct(
        ?string $host = null,
        ?string $apiKey = null,
        ?string $apiSecret = null,
        ?ClientOptions $options = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $host ??= self::env('LIVEKIT_URL');

        if ($host === null || $host === '') {
            throw ConfigurationException::missingHost();
        }

        $this->options = $options ?? new ClientOptions();

        $token = self::nonEmpty($this->options->token);
        $apiKey = self::nonEmpty($apiKey);
        $apiSecret = self::nonEmpty($apiSecret);

        // The environment is consulted only when the caller supplied no credential
        // at all — never field by field. Completing an explicit API key with a
        // secret from the environment, or letting an ambient LIVEKIT_TOKEN stand in
        // for credentials that were passed in, is how a process ends up
        // authenticating as something nobody chose. Every other LiveKit server SDK
        // draws the line in the same place, and for the same reason.
        if ($token === null && $apiKey === null && $apiSecret === null) {
            $token = self::env('LIVEKIT_TOKEN');

            // A token in the environment is a complete credential on its own, so
            // there is nothing left to read. Reading the key and secret anyway
            // would only create a second candidate to choose between.
            if ($token === null) {
                $apiKey = self::env('LIVEKIT_API_KEY');
                $apiSecret = self::env('LIVEKIT_API_SECRET');
            }
        }

        $this->token = $token;
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;

        // A key without its secret is not a credential; a token stands alone.
        if ($token === null && ($apiKey === null || $apiSecret === null)) {
            throw ConfigurationException::missingCredentials();
        }

        $this->transport = new TwirpClient(
            $host,
            $this->options,
            $httpClient,
            $requestFactory,
            $streamFactory,
        );
    }

    /**
     * Mints a fresh short-lived token carrying exactly the grants this call needs,
     * or returns the caller's pre-signed token.
     *
     * Failures propagate. Swallowing them would turn a signing problem into an
     * opaque 401 from the server.
     */
    protected function authHeader(VideoGrant $video, ?SIPGrant $sip = null): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $token = new AccessToken(
            $this->apiKey,
            $this->apiSecret,
            new AccessTokenOptions(ttl: self::SERVICE_TOKEN_TTL_SECONDS),
        );

        $token->addGrant($video);

        if ($sip !== null) {
            $token->addSipGrant($sip);
        }

        return $token->toJwt();
    }

    /**
     * @template T of Message
     *
     * @param class-string<T> $responseClass
     *
     * @return T
     */
    protected function rpc(
        string $service,
        string $method,
        Message $request,
        string $responseClass,
        string $jwt,
        ?int $timeoutSeconds = null,
    ): Message {
        return $this->transport->request(
            $service,
            $method,
            $request,
            $responseClass,
            $jwt,
            $timeoutSeconds,
        );
    }

    /**
     * getenv() returns false when unset, so `??` never fires on its result.
     */
    /** Treats a blank string as absent, so `token: ''` is not a credential. */
    private static function nonEmpty(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
