<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Http;

use Http\Discovery\Exception\NotFoundException as DiscoveryNotFoundException;
use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RetJetApi\Returns\Configuration;
use RetJetApi\Returns\Exception\ConfigurationException;

/**
 * Turns a method/path/query/body tuple into a PSR-7 request.
 *
 * `Accept: application/ld+json` is the default on every request: collections are only
 * paginable in the JSON-LD representation (the plain JSON one is a bare array with no
 * totalItems and no view links), and item responses simply gain a few `@` members that
 * the models keep in raw(). Pass an `Accept` override in $headers to opt out per request.
 *
 * @internal
 */
final class RequestBuilder
{
    public const DEFAULT_ACCEPT = 'application/ld+json';
    public const DEFAULT_CONTENT_TYPE = 'application/json';

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        private readonly Configuration $configuration,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * Builds a request builder, discovering any PSR-17 factory that was not supplied.
     *
     * @throws ConfigurationException when no PSR-17 implementation is installed
     */
    public static function create(
        Configuration $configuration,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ): self {
        try {
            $requestFactory ??= Psr17FactoryDiscovery::findRequestFactory();
            $streamFactory ??= Psr17FactoryDiscovery::findStreamFactory();
        } catch (DiscoveryNotFoundException $exception) {
            throw ConfigurationException::missingDiscovery('PSR-17 factory', $exception);
        }

        return new self($configuration, $requestFactory, $streamFactory);
    }

    /**
     * @param string                          $path    path relative to the base URI, or an
     *                                                 absolute URL (as found in Hydra view links)
     * @param array<string, scalar|null>      $query   null values drop the parameter
     * @param array<array-key, mixed>|null    $body    JSON-encoded; null sends no body
     * @param array<string, string>           $headers overrides the defaults, case-insensitively
     *
     * @throws ConfigurationException when the body cannot be encoded as JSON
     */
    public function build(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        array $headers = [],
    ): RequestInterface {
        $request = $this->requestFactory->createRequest(strtoupper($method), $this->buildUri($path, $query));

        foreach ($this->buildHeaders($body !== null, $headers) as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request = $request->withBody($this->streamFactory->createStream(self::encode($body)));
        }

        return $request;
    }

    /**
     * Absolute URLs are accepted, which is what lets the paginator follow a Hydra
     * `view.next` link - but only when they point at the configured base URI. Every request
     * carries the API key in an Authorization header, and `view.next` is server-controlled
     * data: following it to an arbitrary host would hand the key to that host.
     *
     * @param array<string, scalar|null> $query
     *
     * @throws ConfigurationException when an absolute URL points outside the base URI
     */
    private function buildUri(string $path, array $query): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1) {
            $this->assertSameOrigin($path);
            $target = $path;
        } else {
            $target = $this->configuration->baseUri . '/' . ltrim($path, '/');
        }

        $separator = strpos($target, '?');
        $base = $separator === false ? $target : substr($target, 0, $separator);
        $existing = $separator === false ? '' : substr($target, $separator + 1);

        return $base . self::mergeQuery($existing, $query);
    }

    /**
     * @throws ConfigurationException
     */
    private function assertSameOrigin(string $url): void
    {
        if (self::origin($url) !== self::origin($this->configuration->baseUri)) {
            throw ConfigurationException::crossOriginRequest($url, $this->configuration->baseUri);
        }
    }

    /**
     * Scheme, host and port, lowercased. Returns null for anything unparseable, which then
     * cannot match a base URI Configuration has already validated.
     */
    private static function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : null;
        $host = isset($parts['host']) ? strtolower($parts['host']) : null;

        if ($scheme === null || $host === null) {
            return null;
        }

        $port = $parts['port'] ?? self::defaultPort($scheme);

        return sprintf('%s://%s:%d', $scheme, $host, $port);
    }

    private static function defaultPort(string $scheme): int
    {
        return $scheme === 'https' ? 443 : 80;
    }

    /**
     * Merges $query into an existing query string without round-tripping it through
     * parse_str()/http_build_query(), which silently rewrites names: `filter.name` becomes
     * `filter_name`, `tags[]` becomes `tags[0]` and a valueless `flag` gains an `=`.
     * Untouched pairs are therefore copied across byte for byte; only names named in $query
     * are replaced (or dropped, when null) and appended in their given order.
     *
     * @param array<string, scalar|null> $query
     */
    private static function mergeQuery(string $existing, array $query): string
    {
        $pairs = [];

        foreach ($existing === '' ? [] : explode('&', $existing) as $pair) {
            if ($pair !== '' && !array_key_exists(self::pairName($pair), $query)) {
                $pairs[] = $pair;
            }
        }

        foreach ($query as $name => $value) {
            if ($value !== null) {
                $pairs[] = rawurlencode($name) . '=' . rawurlencode(self::stringify($value));
            }
        }

        return $pairs === [] ? '' : '?' . implode('&', $pairs);
    }

    private static function pairName(string $pair): string
    {
        $equals = strpos($pair, '=');

        return rawurldecode($equals === false ? $pair : substr($pair, 0, $equals));
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function buildHeaders(bool $hasBody, array $overrides): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->configuration->apiKey,
            'Accept' => self::DEFAULT_ACCEPT,
            'User-Agent' => $this->configuration->userAgent,
        ];

        if ($hasBody) {
            $headers['Content-Type'] = self::DEFAULT_CONTENT_TYPE;
        }

        foreach ($overrides as $name => $value) {
            foreach (array_keys($headers) as $known) {
                if (strcasecmp($known, $name) === 0) {
                    unset($headers[$known]);
                }
            }

            $headers[$name] = $value;
        }

        return $headers;
    }

    private static function stringify(bool|float|int|string $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @throws ConfigurationException
     */
    private static function encode(array $body): string
    {
        // An empty array must go out as an empty JSON object: several write endpoints take
        // no fields at all, and `[]` would be sent as a JSON array the API cannot denormalise.
        if ($body === []) {
            return '{}';
        }

        $encoded = json_encode($body, self::JSON_FLAGS);

        if (!is_string($encoded)) {
            throw ConfigurationException::unencodableBody();
        }

        return $encoded;
    }
}
