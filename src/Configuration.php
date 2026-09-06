<?php

declare(strict_types=1);

namespace RetJetApi\Returns;

use Composer\InstalledVersions;
use RetJetApi\Returns\Exception\ConfigurationException;

/**
 * Immutable client configuration.
 *
 * The API key is the only required input; every other setting has a default that works
 * against production, so `new Configuration($key)` is a complete configuration.
 */
final readonly class Configuration
{
    public const DEFAULT_BASE_URI = 'https://api.retjet.com';
    public const DEFAULT_TIMEOUT = 10;
    public const DEFAULT_MAX_RETRIES = 3;

    private const USER_AGENT_PACKAGE = 'retjet/returns-api-php-client';
    private const USER_AGENT_HOMEPAGE = '+https://github.com/RetJet/returns-api-php-client';

    /** Absolute base URI without a trailing slash. */
    public string $baseUri;

    /** The User-Agent header sent with every request; defaultUserAgent() unless overridden. */
    public string $userAgent;

    /**
     * @param string      $apiKey     exchanged for a JWT by the server on every request
     * @param string      $baseUri    absolute http(s) URI; a trailing slash is stripped
     * @param int         $timeout    seconds, at least 1; enforced only on a client the SDK builds
     * @param int         $maxRetries extra attempts RetryMiddleware may make; 0 unplugs it
     * @param string|null $userAgent  defaultUserAgent() when null
     *
     * @throws ConfigurationException when any value is unusable
     */
    public function __construct(
        public string $apiKey,
        string $baseUri = self::DEFAULT_BASE_URI,
        public int $timeout = self::DEFAULT_TIMEOUT,
        public int $maxRetries = self::DEFAULT_MAX_RETRIES,
        ?string $userAgent = null,
    ) {
        if (trim($apiKey) === '') {
            throw ConfigurationException::missingApiKey();
        }

        if ($timeout < 1) {
            throw ConfigurationException::invalidTimeout($timeout);
        }

        if ($maxRetries < 0) {
            throw ConfigurationException::negativeMaxRetries($maxRetries);
        }

        $this->baseUri = self::normaliseBaseUri($baseUri);
        $this->userAgent = $userAgent ?? self::defaultUserAgent();
    }

    /**
     * `retjet-returns-api-php-client/<installed version> (+<homepage>)`.
     *
     * Neither half is written out here. The version is read from Composer at call time, so the
     * header names the release actually running instead of one frozen in source and left stale
     * the moment a new tag is cut. The product token is derived from `USER_AGENT_PACKAGE` with
     * the `/` swapped for a `-`, because RFC 9110 defines a product token as a `token`, and `/`
     * is what separates the product from its version. Deriving it means renaming the package
     * renames the header too, instead of leaving a second name to notice and update by hand.
     *
     * `composer-runtime-api` is a hard requirement, so `InstalledVersions` itself is always
     * present; the fallback to `dev` only covers this package being installed under a different
     * name than `USER_AGENT_PACKAGE` (a fork, a path repository with a renamed `name`).
     */
    public static function defaultUserAgent(): string
    {
        $version = InstalledVersions::isInstalled(self::USER_AGENT_PACKAGE)
            ? InstalledVersions::getPrettyVersion(self::USER_AGENT_PACKAGE)
            : null;

        return sprintf(
            '%s/%s (%s)',
            str_replace('/', '-', self::USER_AGENT_PACKAGE),
            $version ?? 'dev',
            self::USER_AGENT_HOMEPAGE,
        );
    }

    /**
     * @throws ConfigurationException
     */
    private static function normaliseBaseUri(string $baseUri): string
    {
        $trimmed = rtrim(trim($baseUri), '/');

        if ($trimmed === '') {
            throw ConfigurationException::invalidBaseUri($baseUri);
        }

        $parts = parse_url($trimmed);

        if ($parts === false) {
            throw ConfigurationException::invalidBaseUri($baseUri);
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if ($host === null || $scheme === null || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw ConfigurationException::invalidBaseUri($baseUri);
        }

        return $trimmed;
    }
}
