<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Exception;

use InvalidArgumentException;
use Throwable;

/**
 * The SDK was handed something it cannot work with before any request was attempted:
 * a missing API key, a malformed base URI, a body that cannot be serialised, or a missing
 * PSR-17/PSR-18 implementation that discovery could not resolve.
 */
final class ConfigurationException extends InvalidArgumentException implements RetJetException
{
    public static function missingApiKey(): self
    {
        return new self('An API key is required; pass a non-empty string to Configuration.');
    }

    public static function invalidBaseUri(string $baseUri): self
    {
        return new self(sprintf('Base URI "%s" is not an absolute http(s) URI.', $baseUri));
    }

    /**
     * Zero is rejected on purpose: Guzzle's `timeout` and cURL's CURLOPT_TIMEOUT both read 0
     * as "no timeout at all", so accepting it would turn withTimeout(0) into a request that
     * blocks forever - the exact opposite of what the caller asked for.
     */
    public static function invalidTimeout(int $timeout): self
    {
        return new self(sprintf('Timeout must be at least 1 second, got %d.', $timeout));
    }

    public static function negativeMaxRetries(int $maxRetries): self
    {
        return new self(sprintf('Max retries must be zero or more, got %d.', $maxRetries));
    }

    public static function unencodableBody(): self
    {
        return new self('The request body could not be encoded as JSON.');
    }

    /**
     * withTimeout() together with withHttpClient(): PSR-18 exposes no way to apply a timeout
     * to a client somebody else built, so silently dropping it is not an option.
     */
    public static function timeoutOnSuppliedClient(): self
    {
        return new self(
            'A timeout cannot be applied to an HTTP client the SDK did not build. '
            . 'Configure the timeout on the client you pass to withHttpClient(), and drop withTimeout().',
        );
    }

    public static function timeoutNotSupported(): self
    {
        return new self(
            'No HTTP client this SDK can configure a timeout on is installed '
            . '(symfony/http-client, guzzlehttp/guzzle >= 7 or php-http/curl-client). '
            . 'Install one, or build your own client with a timeout and pass it to withHttpClient().',
        );
    }

    /**
     * Bulk operations take a list of RMA request ids. Coercing a malformed entry would send a
     * real id the caller never meant - intval('12a') is 12 - at an endpoint that reports no
     * per-request outcome, so the mistake would be invisible.
     */
    public static function invalidRequestId(mixed $value, int|string $position): self
    {
        return new self(sprintf(
            'requestIds[%s] must be an integer RMA request id, got %s.',
            (string) $position,
            get_debug_type($value),
        ));
    }

    public static function noItemEndpoint(string $resource): self
    {
        return new self(sprintf('%s has no item endpoint; the API exposes the collection only.', $resource));
    }

    /**
     * The API key travels in an Authorization header on every request, so an absolute URL
     * that leaves the configured base URI - a Hydra `view.next` link is server-controlled
     * data - must never be followed.
     */
    public static function crossOriginRequest(string $url, string $baseUri): self
    {
        return new self(sprintf(
            'Refusing to send an authenticated request to "%s": it is outside the configured base URI "%s". '
            . 'The API key would be disclosed to that host.',
            $url,
            $baseUri,
        ));
    }

    public static function missingDiscovery(string $what, Throwable $previous): self
    {
        return new self(
            sprintf('No %s could be discovered. Install one, or pass it explicitly. (%s)', $what, $previous->getMessage()),
            0,
            $previous,
        );
    }
}
