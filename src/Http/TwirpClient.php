<?php

declare(strict_types=1);

namespace LiveKit\Http;

use Google\Protobuf\Internal\Message;
use LiveKit\ClientOptions;
use LiveKit\Enums\WireFormat;
use LiveKit\Exceptions\ConfigurationException;
use LiveKit\Exceptions\SipCallError;
use LiveKit\Exceptions\TwirpException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The only component in this SDK that performs HTTP.
 *
 * Twirp is a thin protocol: POST {host}{prefix}/livekit.{Service}/{Method} with
 * the serialized request as the body. Errors always come back as a JSON
 * {code, msg, meta} envelope, even when the request was binary protobuf.
 */
final class TwirpClient
{
    public const VERSION = '0.1.0';

    public const REQUEST_ID_HEADER = 'X-Livekit-Request-Id';

    public const USER_AGENT_PREFIX = 'livekit-server-sdk-php/';

    private readonly string $host;

    private readonly ClientInterface $httpClient;

    private readonly RequestFactoryInterface $requestFactory;

    private readonly StreamFactoryInterface $streamFactory;

    public function __construct(
        string $host,
        private readonly ClientOptions $options = new ClientOptions(),
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->host = self::normalizeHost($host);
        $this->httpClient = HttpClientResolver::client($httpClient);
        $this->requestFactory = HttpClientResolver::requestFactory($requestFactory);
        $this->streamFactory = HttpClientResolver::streamFactory($streamFactory);
    }

    /**
     * @template T of Message
     *
     * @param class-string<T> $responseClass
     * @param int|null        $timeoutSeconds Overrides ClientOptions::$requestTimeout for this call
     *
     * @return T
     */
    public function request(
        string $service,
        string $method,
        Message $request,
        string $responseClass,
        string $jwt,
        ?int $timeoutSeconds = null,
    ): Message {
        $uri = sprintf('%s%s/livekit.%s/%s', $this->host, $this->options->prefix, $service, $method);

        $body = $this->options->wireFormat === WireFormat::Json
            ? $request->serializeToJsonString()
            : $request->serializeToString();

        $httpRequest = $this->requestFactory->createRequest('POST', $uri)
            ->withHeader('Content-Type', $this->options->wireFormat->contentType())
            ->withHeader('Accept', $this->options->wireFormat->contentType())
            ->withHeader('Authorization', 'Bearer ' . $jwt)
            ->withHeader('User-Agent', self::USER_AGENT_PREFIX . self::VERSION)
            // A fresh id per call. v1 has no retry path, so there is nothing to keep it
            // stable across yet; when phase 2 adds region failover, the retry must REUSE
            // this id rather than generate a new one, or server-side deduplication breaks.
            ->withHeader(self::REQUEST_ID_HEADER, self::requestId())
            ->withHeader('X-Twirp-Timeout-Ms', (string) (($timeoutSeconds ?? $this->options->requestTimeout) * 1000))
            ->withBody($this->streamFactory->createStream($body));

        try {
            $response = $this->httpClient->sendRequest($httpRequest);
        } catch (ClientExceptionInterface $e) {
            throw new TwirpException(
                sprintf('The request to %s failed: %s', $uri, $e->getMessage()),
                'unavailable',
                0,
                [],
                $e,
            );
        }

        $responseBody = (string) $response->getBody();
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw self::errorFromResponse($status, $responseBody);
        }

        $message = new $responseClass();

        if ($this->options->wireFormat === WireFormat::Json) {
            // A 204, or a proxy that strips the body, leaves nothing to decode.
            // Binary mode yields an all-defaults message for empty input, so match
            // that rather than throwing for a response the server considered a success.
            if (trim($responseBody) !== '') {
                try {
                    // The second argument is ignore_unknown. Without it, any field
                    // LiveKit adds to a response throws and breaks the SDK.
                    $message->mergeFromJsonString($responseBody, true);
                } catch (\Throwable $e) {
                    throw new TwirpException(
                        sprintf('Could not decode the JSON response from %s: %s', $uri, $e->getMessage()),
                        'internal',
                        $status,
                        [],
                        $e,
                    );
                }
            }
        } else {
            $message->mergeFromString($responseBody);
        }

        return $message;
    }

    /**
     * Turns a Twirp error envelope into the most specific exception it fits.
     *
     * The SIP-aware subclass is chosen here, from the payload, rather than by the
     * caller naming an exception class. Only CreateSIPParticipant and
     * TransferSIPParticipant ever produce SIP status metadata, but this is the one
     * place that parses the envelope, so this is where the decision belongs — and
     * a caller can never accidentally ask for the wrong class.
     */
    private static function errorFromResponse(int $status, string $body): TwirpException
    {
        $exception = TwirpException::fromResponse($status, $body);
        $meta = $exception->getMeta();

        if (isset($meta['sip_status_code']) || isset($meta['sip_status'])) {
            return SipCallError::fromResponse($status, $body);
        }

        return $exception;
    }

    /**
     * LiveKit accepts ws:// and wss:// hosts for convenience because that is what
     * client SDKs are configured with; the HTTP API lives on the same origin.
     */
    private static function normalizeHost(string $host): string
    {
        $host = trim($host);

        if ($host === '') {
            throw ConfigurationException::missingHost();
        }

        if (str_starts_with($host, 'wss://')) {
            $host = 'https://' . substr($host, 6);
        } elseif (str_starts_with($host, 'ws://')) {
            $host = 'http://' . substr($host, 5);
        }

        return rtrim($host, '/');
    }

    /**
     * A UUID v4 for X-Livekit-Request-Id. No dependency needed for an opaque id.
     */
    private static function requestId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
