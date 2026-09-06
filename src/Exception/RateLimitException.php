<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Exception;

use Throwable;

/**
 * The client exceeded the rate limit (429).
 */
final class RateLimitException extends ApiException
{
    /**
     * @param int|null $retryAfter seconds to wait, taken from the `Retry-After` header;
     *                             null when the header was absent or unparsable
     */
    public function __construct(
        int $statusCode,
        Problem $problem,
        private readonly ?int $retryAfter = null,
        string $method = '',
        string $path = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($statusCode, $problem, $method, $path, $previous);
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
