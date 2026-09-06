<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Exception;

use RuntimeException;
use Throwable;

/**
 * Base class for every error the API itself reported, i.e. a response with a >= 400 status.
 *
 * Subclasses carry no extra behaviour beyond narrowing the status, so callers can either
 * catch a specific one or catch this class for "the API said no" in general.
 */
class ApiException extends RuntimeException implements RetJetException
{
    /**
     * $method and $path default to empty: every exception built by the SDK itself supplies
     * them, but the constructor stays usable without a request in hand, e.g. in tests that
     * only care about the status and the problem document.
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly Problem $problem,
        private readonly string $method = '',
        private readonly string $path = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct(self::describe($statusCode, $problem, $method, $path), $statusCode, $previous);
    }

    /**
     * HTTP status of the response, which is authoritative even when the body carries a
     * different `status` member.
     */
    public function status(): int
    {
        return $this->statusCode;
    }

    /**
     * The RFC 7807 document from the response body. Always present; its members are null
     * when the server answered with something that was not a problem document.
     */
    public function problem(): Problem
    {
        return $this->problem;
    }

    /**
     * HTTP method of the request that failed, e.g. "GET". Empty when the exception was built
     * without request context.
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * Path of the request that failed - no query string, no host. The query can carry search
     * terms or other data the caller supplied and the host adds nothing the caller does not
     * already know, so neither belongs in an exception that ends up logged or reported.
     * Empty when the exception was built without request context.
     */
    public function path(): string
    {
        return $this->path;
    }

    private static function describe(int $statusCode, Problem $problem, string $method, string $path): string
    {
        $summary = sprintf('HTTP %d: %s', $statusCode, $problem->summary() ?? 'RetJet API request failed');

        if ($method === '' && $path === '') {
            return $summary;
        }

        return sprintf('%s (%s %s)', $summary, $method, $path);
    }
}
