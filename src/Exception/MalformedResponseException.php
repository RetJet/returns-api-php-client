<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Exception;

use RuntimeException;

/**
 * The server answered with a success status but a body the SDK cannot use: not JSON, or
 * JSON that is neither an object nor an array.
 *
 * Deliberately not an ApiException: that class carries an HTTP status and is documented as
 * ">= 400", so reporting a 200 through it would break status-based branching and the retry
 * middleware. This is a protocol failure, not an API error response.
 */
final class MalformedResponseException extends RuntimeException implements RetJetException
{
    public function __construct(private readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public static function notJson(int $status): self
    {
        return new self(
            $status,
            sprintf('The response body is not a JSON object or array (HTTP %d).', $status),
        );
    }

    /** The success status the unusable body arrived with. */
    public function status(): int
    {
        return $this->status;
    }
}
