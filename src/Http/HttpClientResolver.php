<?php

declare(strict_types=1);

namespace LiveKit\Http;

use Http\Discovery\Exception\NotFoundException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use LiveKit\Exceptions\ConfigurationException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Resolves PSR-18 and PSR-17 implementations, preferring whatever the caller
 * injected and falling back to php-http/discovery.
 *
 * Note the exception type: Psr18ClientDiscovery::find() catches
 * DiscoveryFailedException internally and rethrows NotFoundException, so
 * catching DiscoveryFailedException here would never fire.
 */
final class HttpClientResolver
{
    public static function client(?ClientInterface $client): ClientInterface
    {
        if ($client !== null) {
            return $client;
        }

        try {
            return Psr18ClientDiscovery::find();
        } catch (NotFoundException $e) {
            throw ConfigurationException::noHttpClient($e);
        }
    }

    public static function requestFactory(?RequestFactoryInterface $factory): RequestFactoryInterface
    {
        if ($factory !== null) {
            return $factory;
        }

        try {
            return Psr17FactoryDiscovery::findRequestFactory();
        } catch (NotFoundException $e) {
            throw ConfigurationException::noHttpFactory($e);
        }
    }

    public static function streamFactory(?StreamFactoryInterface $factory): StreamFactoryInterface
    {
        if ($factory !== null) {
            return $factory;
        }

        try {
            return Psr17FactoryDiscovery::findStreamFactory();
        } catch (NotFoundException $e) {
            throw ConfigurationException::noHttpFactory($e);
        }
    }
}
