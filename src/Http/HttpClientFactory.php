<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Http;

use Http\Discovery\Exception\NotFoundException as DiscoveryNotFoundException;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use RetJetApi\Returns\Exception\ConfigurationException;

/**
 * Builds the PSR-18 client when the caller did not supply one.
 *
 * PSR-18 has no notion of a timeout - sendRequest() is blocking and there is no way to
 * interrupt it from the outside - so a timeout can only be honoured by configuring the
 * client at construction time. That is the entire reason this class exists instead of
 * calling Psr18ClientDiscovery::find() directly: a discovered client comes with whatever
 * defaults it happens to have, and Configuration::$timeout would quietly mean nothing.
 *
 * The three implementations handled here are the ones php-http/discovery itself shortlists
 * for PSR-18 (Symfony, Guzzle, php-http/curl-client), which is also everything Symfony and
 * Laravel projects realistically ship. They are constructed by name and then checked against
 * ClientInterface, so no optional package has to be installed for this file to load - and a
 * Guzzle older than 7, which does not implement PSR-18, is correctly rejected.
 *
 * @internal
 */
final class HttpClientFactory
{
    /**
     * @param int  $timeout          seconds
     * @param bool $timeoutRequested whether the caller asked for this timeout explicitly, as
     *                               opposed to inheriting the SDK default
     *
     * @throws ConfigurationException when an explicit timeout cannot be applied to anything
     *                                installed, or when no PSR-18 client exists at all
     */
    public static function create(int $timeout, bool $timeoutRequested = false): ClientInterface
    {
        // An explicit timeout can only be honoured by a client this SDK constructs itself.
        if ($timeoutRequested) {
            return self::configurable($timeout) ?? throw ConfigurationException::timeoutNotSupported();
        }

        // Nobody asked for a timeout, so discovery goes first: an application that registered
        // its own strategy (a recording client, one with proxy or CA settings) must get that
        // client rather than a fresh one built behind its back. Only when discovery resolves
        // nothing do we fall back to constructing a client we know how to configure.
        try {
            return Psr18ClientDiscovery::find();
        } catch (DiscoveryNotFoundException $exception) {
            return self::configurable($timeout)
                ?? throw ConfigurationException::missingDiscovery('PSR-18 HTTP client', $exception);
        }
    }

    /**
     * Whether a timeout-honouring client can be built in this installation. False means
     * withTimeout() has nothing to configure and will be rejected rather than ignored.
     */
    public static function supportsTimeout(): bool
    {
        return self::configurable(1) !== null;
    }

    /**
     * Constructing these clients can itself run PSR-17 discovery internally, which throws
     * php-http's own NotFoundException - not a RetJetException. Swallowing it here keeps that
     * foreign type from escaping create(), which is documented as throwing
     * ConfigurationException only, and lets supportsTimeout() answer false instead of blowing up.
     */
    private static function configurable(int $timeout): ?ClientInterface
    {
        try {
            return self::symfony($timeout) ?? self::guzzle($timeout) ?? self::curl($timeout);
        } catch (DiscoveryNotFoundException) {
            return null;
        }
    }

    private static function symfony(int $timeout): ?ClientInterface
    {
        $psr18Class = 'Symfony\\Component\\HttpClient\\Psr18Client';
        $factoryClass = 'Symfony\\Component\\HttpClient\\HttpClient';

        if (!class_exists($psr18Class) || !class_exists($factoryClass)) {
            return null;
        }

        $factory = [$factoryClass, 'create'];

        if (!is_callable($factory)) {
            return null;
        }

        return self::asPsr18Client(new $psr18Class($factory(['timeout' => $timeout])));
    }

    private static function guzzle(int $timeout): ?ClientInterface
    {
        $class = 'GuzzleHttp\\Client';

        if (!class_exists($class)) {
            return null;
        }

        return self::asPsr18Client(new $class(['timeout' => $timeout, 'connect_timeout' => $timeout]));
    }

    private static function curl(int $timeout): ?ClientInterface
    {
        $class = 'Http\\Client\\Curl\\Client';

        if (!class_exists($class) || !extension_loaded('curl')) {
            return null;
        }

        $options = [CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => $timeout];

        return self::asPsr18Client(new $class(null, null, $options));
    }

    private static function asPsr18Client(mixed $client): ?ClientInterface
    {
        return $client instanceof ClientInterface ? $client : null;
    }
}
