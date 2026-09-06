<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Support;

use RetJetApi\Returns\Http\Middleware\MiddlewareInterface;
use RetJetApi\Returns\Http\Transport;

/**
 * Does nothing but record that it was entered, so a test can see which middleware the stack
 * reaches first. Also stands in for user-supplied middleware in the builder tests.
 */
final class TappingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Transport $next,
        private readonly string $label,
        private readonly CallTrail $trail,
    ) {
    }

    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        array $headers = [],
    ): array {
        $this->trail->add($this->label);

        return $this->next->request($method, $path, $query, $body, $headers);
    }
}
