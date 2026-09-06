<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Support;

use LogicException;
use RetJetApi\Returns\Exception\RetJetException;
use RetJetApi\Returns\Http\Transport;

/**
 * Transport stub for middleware tests: outcomes are queued up front and consumed in order,
 * and every call is recorded with the exact arguments it was made with.
 *
 * Sits one layer above MockHttpClient - a middleware decorates a Transport, so testing one
 * against a fake Transport keeps PSR-7 and the request builder out of the picture entirely.
 */
final class RecordingTransport implements Transport
{
    /** @var list<array<string, mixed>|RetJetException> */
    private array $queue = [];

    /** @var list<array{method: string, path: string, query: array<string, scalar|null>, body: array<array-key, mixed>|null, headers: array<string, string>}> */
    private array $calls = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function willReturn(array $payload = []): self
    {
        $this->queue[] = $payload;

        return $this;
    }

    public function willThrow(RetJetException $exception, int $times = 1): self
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->queue[] = $exception;
        }

        return $this;
    }

    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        array $headers = [],
    ): array {
        $this->calls[] = [
            'method' => $method,
            'path' => $path,
            'query' => $query,
            'body' => $body,
            'headers' => $headers,
        ];

        if ($this->queue === []) {
            throw new LogicException(sprintf('RecordingTransport has no queued outcome for %s %s.', $method, $path));
        }

        $next = array_shift($this->queue);

        if ($next instanceof RetJetException) {
            throw $next;
        }

        return $next;
    }

    /**
     * @return list<array{method: string, path: string, query: array<string, scalar|null>, body: array<array-key, mixed>|null, headers: array<string, string>}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}
