<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Exception;

use Throwable;

/**
 * The payload was well formed but rejected by validation (422).
 *
 * The API returns a flat `violations[]` list; this exception regroups it by
 * `propertyPath` so a form-style consumer can look up the messages for one field.
 * Violations that are not tied to a property arrive under the empty-string key.
 */
final class ValidationException extends ApiException
{
    /**
     * @param array<string, list<string>> $violations messages keyed by property path
     */
    public function __construct(
        int $statusCode,
        Problem $problem,
        private readonly array $violations = [],
        string $method = '',
        string $path = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($statusCode, $problem, $method, $path, $previous);
    }

    /**
     * @return array<string, list<string>>
     */
    public function violations(): array
    {
        return $this->violations;
    }

    /**
     * @return list<string> messages for one property path, empty when it validated cleanly
     */
    public function violationsFor(string $propertyPath): array
    {
        return $this->violations[$propertyPath] ?? [];
    }
}
