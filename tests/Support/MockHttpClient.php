<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Support;

use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client stub: responses are queued up front and consumed in order, and every
 * request that goes through is recorded for later assertions.
 *
 * Deliberately hand written rather than built on a mocking library or Guzzle's handler
 * stack - the SDK has no such dependency and the tests should not introduce one.
 */
final class MockHttpClient implements ClientInterface
{
    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    private array $requests = [];

    private readonly Psr17Factory $factory;

    public function __construct()
    {
        $this->factory = new Psr17Factory();
    }

    /**
     * @param array<string, string> $headers
     */
    public function willRespond(int $status, string $body = '', array $headers = []): self
    {
        $response = $this->factory->createResponse($status)
            ->withBody($this->factory->createStream($body));

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $this->queue[] = $response;

        return $this;
    }

    /**
     * @param array<array-key, mixed> $payload
     * @param array<string, string>   $headers
     */
    public function willRespondWithJson(
        int $status,
        array $payload,
        array $headers = [],
        string $contentType = 'application/ld+json',
    ): self {
        return $this->willRespond(
            $status,
            (string) json_encode($payload),
            $headers + ['Content-Type' => $contentType],
        );
    }

    public function willThrow(ClientExceptionInterface $exception): self
    {
        $this->queue[] = $exception;

        return $this;
    }

    public function willFail(string $message = 'Connection refused'): self
    {
        return $this->willThrow(new MockClientException($message));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($this->queue === []) {
            throw new LogicException(sprintf(
                'MockHttpClient has no queued response for %s %s.',
                $request->getMethod(),
                (string) $request->getUri(),
            ));
        }

        $next = array_shift($this->queue);

        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    public function lastRequest(): RequestInterface
    {
        $last = end($this->requests);

        if ($last === false) {
            throw new LogicException('MockHttpClient recorded no requests.');
        }

        return $last;
    }
}
