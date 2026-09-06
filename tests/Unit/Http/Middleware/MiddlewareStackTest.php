<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RetJetApi\Returns\Client;
use RetJetApi\Returns\ClientBuilder;
use RetJetApi\Returns\Exception\RateLimitException;
use RetJetApi\Returns\Exception\ServerException;
use RetJetApi\Returns\Http\Middleware\LoggingMiddleware;
use RetJetApi\Returns\Http\Middleware\RetryMiddleware;
use RetJetApi\Returns\Http\PsrTransport;
use RetJetApi\Returns\Http\Transport;
use RetJetApi\Returns\Tests\Support\CallTrail;
use RetJetApi\Returns\Tests\Support\Fixtures;
use RetJetApi\Returns\Tests\Support\MockHttpClient;
use RetJetApi\Returns\Tests\Support\RecordingLogger;
use RetJetApi\Returns\Tests\Support\TappingMiddleware;

/**
 * How ClientBuilder assembles the stack: what is in it by default, what has to be asked for,
 * and in which order the layers run.
 *
 * The rate-limit responses here carry `Retry-After: 0`, which is what keeps these tests
 * instant while still exercising the real, builder-wired RetryMiddleware and its real sleep.
 */
#[CoversClass(ClientBuilder::class)]
#[CoversClass(RetryMiddleware::class)]
#[CoversClass(LoggingMiddleware::class)]
final class MiddlewareStackTest extends TestCase
{
    private MockHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
    }

    private function queueRateLimit(int $times = 1): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->http->willRespondWithJson(429, ['detail' => 'Too Many Requests'], ['Retry-After' => '0']);
        }
    }

    // ------------------------------------------------------------------- retry by default

    /**
     * Nobody should have to know retrying exists for a transient rate limit to be survived.
     */
    public function testCreateRetriesARateLimitWithoutAnyoneAskingForIt(): void
    {
        $this->queueRateLimit();
        $this->http->willRespondWithJson(200, Fixtures::hydraCollection([Fixtures::load('sale_channel')]));

        $page = Client::create('secret-key', $this->http)->saleChannels()->list();

        self::assertSame(2, $this->http->requestCount());
        self::assertCount(1, $page);
    }

    public function testTheDefaultBudgetIsThreeRetries(): void
    {
        $this->queueRateLimit(4);

        $this->expectException(RateLimitException::class);

        try {
            Client::create('secret-key', $this->http)->saleChannels()->list();
        } finally {
            self::assertSame(4, $this->http->requestCount(), 'One attempt plus three retries.');
        }
    }

    public function testWithRetryChangesTheBudget(): void
    {
        $this->queueRateLimit(2);

        try {
            Client::builder()
                ->withApiKey('k')
                ->withHttpClient($this->http)
                ->withRetry(1)
                ->build()
                ->saleChannels()
                ->list();
        } catch (RateLimitException) {
            // expected
        }

        self::assertSame(2, $this->http->requestCount());
    }

    /**
     * withRetry(0) does not install a middleware that would never act - it leaves it out.
     */
    public function testWithRetryZeroUnplugsRetryingEntirely(): void
    {
        $this->queueRateLimit(2);

        $this->expectException(RateLimitException::class);

        try {
            Client::builder()
                ->withApiKey('k')
                ->withHttpClient($this->http)
                ->withRetry(0)
                ->build()
                ->saleChannels()
                ->list();
        } finally {
            self::assertSame(1, $this->http->requestCount());
        }
    }

    /**
     * The design decision that matters most in practice: a write is not replayed after an
     * ambiguous failure, even though retrying is on by default.
     */
    public function testADefaultClientStillDoesNotReplayAWriteAfterAServerError(): void
    {
        $this->http->willRespondWithJson(500, ['detail' => 'Internal Server Error']);
        $this->http->willRespondWithJson(201, Fixtures::load('rma_request'));

        $client = Client::create('secret-key', $this->http);

        try {
            $client->transport()->request('POST', '/v1/rma-requests', [], ['type' => 'return']);
            self::fail('Expected the server error to propagate.');
        } catch (ServerException) {
            // expected
        }

        self::assertSame(1, $this->http->requestCount(), 'The POST must not have been repeated.');
    }

    // ------------------------------------------------------------------- logging on request

    /**
     * The stack holds exactly what was asked for: retrying is in it by default, logging only
     * once a logger is supplied, and with neither the transport is left undecorated.
     */
    public function testTheStackContainsOnlyWhatWasAskedFor(): void
    {
        $bare = Client::builder()->withApiKey('k')->withHttpClient($this->http)->withRetry(0)->build();

        self::assertInstanceOf(PsrTransport::class, $bare->transport(), 'Nothing wraps the transport.');
        self::assertNull($bare->logger());

        $default = Client::create('secret-key', $this->http);

        self::assertInstanceOf(RetryMiddleware::class, $default->transport(), 'Retrying is wired in by default.');
        self::assertNull($default->logger(), 'No logger means no LoggingMiddleware.');
    }

    public function testWithLoggerPutsLoggingIntoTheStack(): void
    {
        $this->http->willRespondWithJson(200, Fixtures::hydraCollection([]));

        $logger = new RecordingLogger();

        Client::builder()
            ->withApiKey('k')
            ->withHttpClient($this->http)
            ->withLogger($logger)
            ->build()
            ->saleChannels()
            ->list();

        self::assertSame(['RetJet API request', 'RetJet API response'], $logger->messages());
        self::assertCount(2, $logger->recordsAtLevel(LogLevel::DEBUG));
    }

    // ------------------------------------------------------------------- ordering

    /**
     * Custom middleware wraps everything, and the last one registered is the outermost, so it
     * sees a call first. That is what a short-circuiting middleware such as a cache needs.
     */
    public function testCustomMiddlewareRunsOutermostAndInRegistrationOrder(): void
    {
        $this->http->willRespondWithJson(200, Fixtures::hydraCollection([]));

        $trail = new CallTrail();

        Client::builder()
            ->withApiKey('k')
            ->withHttpClient($this->http)
            ->withMiddleware(static fn (Transport $next): Transport => new TappingMiddleware($next, 'first', $trail))
            ->withMiddleware(static fn (Transport $next): Transport => new TappingMiddleware($next, 'second', $trail))
            ->build()
            ->saleChannels()
            ->list();

        self::assertSame(['second', 'first'], $trail->entries());
    }

    /**
     * Custom middleware sits outside retrying, so it sees one logical call rather than one
     * per attempt.
     */
    public function testCustomMiddlewareSitsOutsideRetrying(): void
    {
        $this->queueRateLimit(2);
        $this->http->willRespondWithJson(200, Fixtures::hydraCollection([]));

        $trail = new CallTrail();

        Client::builder()
            ->withApiKey('k')
            ->withHttpClient($this->http)
            ->withMiddleware(static fn (Transport $next): Transport => new TappingMiddleware($next, 'custom', $trail))
            ->build()
            ->saleChannels()
            ->list();

        self::assertSame(3, $this->http->requestCount());
        self::assertSame(['custom'], $trail->entries(), 'Entered once, not once per attempt.');
    }

    /**
     * Logging sits inside retrying, so a retry storm is visible in the log instead of being
     * collapsed into a single line.
     */
    public function testLoggingSitsInsideRetryingSoEveryAttemptIsVisible(): void
    {
        $this->queueRateLimit(2);
        $this->http->willRespondWithJson(200, Fixtures::hydraCollection([]));

        $logger = new RecordingLogger();

        Client::builder()
            ->withApiKey('k')
            ->withHttpClient($this->http)
            ->withLogger($logger)
            ->build()
            ->saleChannels()
            ->list();

        self::assertCount(2, $logger->recordsAtLevel(LogLevel::WARNING));
        self::assertCount(4, $logger->recordsAtLevel(LogLevel::DEBUG));
    }

    public function testTheWholeStackComposesInOneBuild(): void
    {
        $this->queueRateLimit();
        $this->http->willRespondWithJson(200, Fixtures::hydraCollection([Fixtures::load('sale_channel')]));

        $trail = new CallTrail();
        $logger = new RecordingLogger();

        $page = Client::builder()
            ->withApiKey('k')
            ->withHttpClient($this->http)
            ->withLogger($logger)
            ->withRetry(2)
            ->withMiddleware(static fn (Transport $next): Transport => new TappingMiddleware($next, 'custom', $trail))
            ->build()
            ->saleChannels()
            ->list();

        self::assertCount(1, $page);
        self::assertSame(['custom'], $trail->entries());
        self::assertCount(1, $logger->recordsAtLevel(LogLevel::WARNING));
        self::assertSame(2, $this->http->requestCount());
    }
}
