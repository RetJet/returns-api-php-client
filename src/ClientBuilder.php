<?php

declare(strict_types=1);

namespace RetJetApi\Returns;

use Closure;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use RetJetApi\Returns\Exception\ConfigurationException;
use RetJetApi\Returns\Http\HttpClientFactory;
use RetJetApi\Returns\Http\Middleware\LoggingMiddleware;
use RetJetApi\Returns\Http\Middleware\RetryMiddleware;
use RetJetApi\Returns\Http\PsrTransport;
use RetJetApi\Returns\Http\RequestBuilder;
use RetJetApi\Returns\Http\ResponseParser;
use RetJetApi\Returns\Http\Transport;

/**
 * Fluent configuration for Client.
 *
 * Only withApiKey() is required; every other setting has a default that works against
 * production. The builder is mutable and returns itself - it is a construction helper, not a
 * value object, and each call is meant to be chained:
 *
 *     Client::builder()
 *         ->withApiKey($key)
 *         ->withBaseUri('https://api.example.com')
 *         ->withTimeout(30)
 *         ->build();
 */
final class ClientBuilder
{
    private ?string $apiKey = null;

    private string $baseUri = Configuration::DEFAULT_BASE_URI;

    private ?int $timeout = null;

    private int $maxRetries = Configuration::DEFAULT_MAX_RETRIES;

    private ?string $userAgent = null;

    private ?ClientInterface $httpClient = null;

    private ?LoggerInterface $logger = null;

    /** @var list<Closure(Transport): Transport> */
    private array $middleware = [];

    public function withApiKey(string $apiKey): self
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    /**
     * Override the production host, e.g. for the dev environment or a local proxy.
     */
    public function withBaseUri(string $baseUri): self
    {
        $this->baseUri = $baseUri;

        return $this;
    }

    /**
     * Use a PSR-18 client the caller already owns instead of letting the SDK build one.
     *
     * Configure the timeout on that client: withTimeout() is rejected in combination with
     * this method, because PSR-18 offers no way to apply one after the fact.
     */
    public function withHttpClient(ClientInterface $httpClient): self
    {
        $this->httpClient = $httpClient;

        return $this;
    }

    /**
     * Request timeout in seconds. Honoured by the client the SDK builds; see
     * HttpClientFactory for which implementations that covers.
     */
    public function withTimeout(int $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Retry budget: how many *extra* attempts a failed request may make. withRetry(0) leaves
     * RetryMiddleware out of the stack entirely.
     */
    public function withRetry(int $maxRetries): self
    {
        $this->maxRetries = $maxRetries;

        return $this;
    }

    /**
     * PSR-3 logger. Supplying one puts a LoggingMiddleware into the transport stack; without
     * it nothing is logged. Bodies are never written to the log and credentials are masked -
     * see LoggingMiddleware.
     */
    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function withUserAgent(string $userAgent): self
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    /**
     * Adds a custom decorator to the transport stack.
     *
     * The transport a middleware wraps only exists once build() runs, so what is registered
     * is a factory rather than an instance:
     *
     *     ->withMiddleware(fn (Transport $next) => new CacheMiddleware($next, $pool))
     *
     * Each registered factory wraps everything registered before it, so the last one added
     * is the outermost and sees a call first - which is what a short-circuiting middleware
     * such as a cache needs.
     *
     * @param callable(Transport): Transport $factory
     */
    public function withMiddleware(callable $factory): self
    {
        $this->middleware[] = static fn (Transport $next): Transport => $factory($next);

        return $this;
    }

    /**
     * @throws ConfigurationException when the API key is missing, a value is unusable, or a
     *                                timeout was asked for that cannot be applied
     */
    public function build(): Client
    {
        if ($this->apiKey === null) {
            throw ConfigurationException::missingApiKey();
        }

        $configuration = new Configuration(
            $this->apiKey,
            $this->baseUri,
            $this->timeout ?? Configuration::DEFAULT_TIMEOUT,
            $this->maxRetries,
            $this->userAgent,
        );

        $transport = new PsrTransport(
            $this->resolveHttpClient($configuration),
            RequestBuilder::create($configuration),
            new ResponseParser(),
        );

        return new Client($this->decorate($transport, $configuration), $configuration, $this->logger);
    }

    /**
     * A timeout can only be applied while the client is being constructed, so the SDK either
     * builds the client itself and honours it, or refuses the combination outright. Quietly
     * accepting withTimeout() and doing nothing with it would be the worst of the three.
     *
     * @throws ConfigurationException
     */
    private function resolveHttpClient(Configuration $configuration): ClientInterface
    {
        if ($this->httpClient === null) {
            return HttpClientFactory::create($configuration->timeout, $this->timeout !== null);
        }

        if ($this->timeout !== null) {
            throw ConfigurationException::timeoutOnSuppliedClient();
        }

        return $this->httpClient;
    }

    /**
     * The single place the middleware stack is assembled.
     *
     * Order matters, and the stack is built inside out:
     *
     *     PsrTransport -> LoggingMiddleware -> RetryMiddleware -> custom middleware
     *
     * Logging sits *inside* retrying so that every attempt produces its own record: when a
     * call is retried three times the log says so, which is exactly the situation the log is
     * there to explain. Custom middleware goes outermost, so a cache can answer without
     * waking either of the other two.
     *
     * RetryMiddleware is in the stack by default; withRetry(0) leaves maxRetries at zero and
     * unplugs it entirely rather than installing a decorator that would never act.
     */
    private function decorate(Transport $transport, Configuration $configuration): Transport
    {
        if ($this->logger !== null) {
            $transport = new LoggingMiddleware($transport, $this->logger);
        }

        if ($configuration->maxRetries > 0) {
            $transport = new RetryMiddleware($transport, $configuration->maxRetries);
        }

        foreach ($this->middleware as $factory) {
            $transport = $factory($transport);
        }

        return $transport;
    }
}
