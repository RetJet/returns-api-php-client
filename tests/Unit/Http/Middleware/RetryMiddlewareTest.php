<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RetJetApi\Returns\Exception\AccessDeniedException;
use RetJetApi\Returns\Exception\ApiException;
use RetJetApi\Returns\Exception\AuthenticationException;
use RetJetApi\Returns\Exception\MalformedResponseException;
use RetJetApi\Returns\Exception\NotFoundException;
use RetJetApi\Returns\Exception\Problem;
use RetJetApi\Returns\Exception\RateLimitException;
use RetJetApi\Returns\Exception\RetJetException;
use RetJetApi\Returns\Exception\ServerException;
use RetJetApi\Returns\Exception\TransportException;
use RetJetApi\Returns\Exception\ValidationException;
use RetJetApi\Returns\Http\Middleware\RetryMiddleware;
use RetJetApi\Returns\Tests\Support\RecordingSleeper;
use RetJetApi\Returns\Tests\Support\RecordingTransport;

#[CoversClass(RetryMiddleware::class)]
final class RetryMiddlewareTest extends TestCase
{
    private RecordingTransport $inner;

    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->inner = new RecordingTransport();
        $this->sleeper = new RecordingSleeper();
    }

    private function middleware(
        int $maxRetries = 3,
        float $maxDelay = RetryMiddleware::DEFAULT_MAX_DELAY,
        float $maxTotalDelay = RetryMiddleware::DEFAULT_MAX_TOTAL_DELAY,
    ): RetryMiddleware {
        return new RetryMiddleware($this->inner, $maxRetries, $this->sleeper, 1.0, $maxDelay, $maxTotalDelay);
    }

    private static function rateLimit(?int $retryAfter = null): RateLimitException
    {
        return new RateLimitException(429, new Problem(null, null, 429, 'Too Many Requests'), $retryAfter);
    }

    private static function serverError(): ServerException
    {
        return new ServerException(500, new Problem(null, null, 500, 'Internal Server Error'));
    }

    // ------------------------------------------------------------------- the happy path

    public function testASuccessfulCallIsNotRetried(): void
    {
        $this->inner->willReturn(['id' => 1]);

        self::assertSame(['id' => 1], $this->middleware()->request('GET', '/v1/rma-requests/1'));
        self::assertSame(1, $this->inner->callCount());
        self::assertSame(0, $this->sleeper->slept());
    }

    /**
     * A retry has to replay the same request, not a reconstruction of it.
     */
    public function testEveryAttemptReplaysTheRequestVerbatim(): void
    {
        $this->inner->willThrow(self::serverError(), 2)->willReturn(['ok' => true]);

        $this->middleware()->request(
            'PUT',
            '/v1/rma-requests/1/product/42',
            ['page' => 2],
            ['confirmedQty' => 1],
            ['X-Request-Id' => 'abc'],
        );

        self::assertSame(3, $this->inner->callCount());
        self::assertSame([$this->inner->calls()[0]], array_unique($this->inner->calls(), SORT_REGULAR));
    }

    // ------------------------------------------------------------------- 429

    public function testARateLimitIsRetriedAndHonoursRetryAfter(): void
    {
        $this->inner->willThrow(self::rateLimit(7))->willReturn(['ok' => true]);

        self::assertSame(['ok' => true], $this->middleware()->request('GET', '/v1/sale-channels'));
        self::assertSame(2, $this->inner->callCount());
        self::assertSame([7.0], $this->sleeper->delays(), "The server's own number beats a guess.");
    }

    public function testARateLimitWithoutRetryAfterFallsBackToBackoff(): void
    {
        $this->inner->willThrow(self::rateLimit())->willReturn([]);

        $this->middleware()->request('GET', '/v1/sale-channels');

        self::assertSame([1.0], $this->sleeper->delays());
    }

    public function testARetryAfterOfZeroIsHonouredRatherThanTreatedAsAbsent(): void
    {
        $this->inner->willThrow(self::rateLimit(0))->willReturn([]);

        $this->middleware()->request('GET', '/v1/sale-channels');

        self::assertSame([0.0], $this->sleeper->delays());
        self::assertSame(2, $this->inner->callCount());
    }

    /**
     * The one case where a POST is replayed: a 429 is answered before the request reaches the
     * handler, so nothing was processed and replaying cannot duplicate anything.
     */
    public function testARateLimitIsRetriedEvenOnANonIdempotentMethod(): void
    {
        $this->inner->willThrow(self::rateLimit(3))->willReturn(['id' => 1]);

        $this->middleware()->request('POST', '/v1/rma-requests', [], ['type' => 'return']);

        self::assertSame(2, $this->inner->callCount());
        self::assertSame([3.0], $this->sleeper->delays());
    }

    /**
     * Blocking a worker for a quarter of an hour is worse than reporting the failure, so a
     * delay past the cap gives up instead - with retryAfter() intact for the caller.
     */
    public function testARetryAfterBeyondTheCapGivesUpImmediately(): void
    {
        $this->inner->willThrow(self::rateLimit(900));

        try {
            $this->middleware(maxRetries: 3, maxDelay: 60.0)->request('GET', '/v1/sale-channels');
            self::fail('Expected a RateLimitException.');
        } catch (RateLimitException $exception) {
            self::assertSame(900, $exception->retryAfter());
        }

        self::assertSame(1, $this->inner->callCount());
        self::assertSame(0, $this->sleeper->slept());
    }

    // ------------------------------------------------------------------- 5xx and network

    /**
     * @return iterable<string, array{string}>
     */
    public static function idempotentMethods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'PUT' => ['PUT'];
        yield 'DELETE' => ['DELETE'];
        yield 'lower case' => ['get'];
    }

    #[DataProvider('idempotentMethods')]
    public function testAServerErrorIsRetriedOnIdempotentMethods(string $method): void
    {
        $this->inner->willThrow(self::serverError())->willReturn(['ok' => true]);

        self::assertSame(['ok' => true], $this->middleware()->request($method, '/v1/rma-requests/1/star'));
        self::assertSame(2, $this->inner->callCount());
    }

    #[DataProvider('idempotentMethods')]
    public function testATransportFailureIsRetriedOnIdempotentMethods(string $method): void
    {
        $this->inner->willThrow(new TransportException('Connection refused'))->willReturn([]);

        $this->middleware()->request($method, '/v1/sale-channels');

        self::assertSame(2, $this->inner->callCount());
    }

    /**
     * @return iterable<string, array{string, RetJetException}>
     */
    public static function unsafeReplays(): iterable
    {
        yield 'POST after 5xx' => ['POST', self::serverError()];
        yield 'POST after a network failure' => ['POST', new TransportException('Connection reset by peer')];
        yield 'PATCH after 5xx' => ['PATCH', self::serverError()];
    }

    /**
     * The core of the design: a 5xx or a dropped connection leaves it unknown whether the
     * server already acted, so replaying a write could create the resource twice.
     */
    #[DataProvider('unsafeReplays')]
    public function testAWriteIsNotReplayedAfterAnAmbiguousFailure(string $method, RetJetException $failure): void
    {
        $this->inner->willThrow($failure);

        try {
            $this->middleware()->request($method, '/v1/rma-requests', [], ['type' => 'return']);
            self::fail('Expected the failure to propagate.');
        } catch (RetJetException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(1, $this->inner->callCount(), 'The write must not have been repeated.');
        self::assertSame(0, $this->sleeper->slept());
    }

    // ------------------------------------------------------------------- never retried

    /**
     * @return iterable<string, array{RetJetException}>
     */
    public static function permanentFailures(): iterable
    {
        yield '401' => [new AuthenticationException(401, new Problem())];
        yield '403' => [new AccessDeniedException(403, new Problem())];
        yield '404' => [new NotFoundException(404, new Problem())];
        yield '409' => [new ApiException(409, new Problem())];
        yield '422' => [new ValidationException(422, new Problem())];
        yield 'malformed body' => [MalformedResponseException::notJson(200)];
    }

    /**
     * Repeating any of these produces the same answer, so a retry only delays the error.
     */
    #[DataProvider('permanentFailures')]
    public function testAPermanentFailureIsNeverRetried(RetJetException $failure): void
    {
        $this->inner->willThrow($failure);

        try {
            $this->middleware()->request('GET', '/v1/sale-channels');
            self::fail('Expected the failure to propagate.');
        } catch (RetJetException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(1, $this->inner->callCount());
        self::assertSame(0, $this->sleeper->slept());
    }

    // ------------------------------------------------------------------- budget and backoff

    public function testTheBackoffIsExponentialAndTheBudgetIsSpentExactlyOnce(): void
    {
        $this->inner->willThrow(self::serverError(), 4);

        $this->expectException(ServerException::class);

        try {
            $this->middleware(maxRetries: 3)->request('GET', '/v1/sale-channels');
        } finally {
            self::assertSame(4, $this->inner->callCount(), 'One original attempt plus three retries.');
            self::assertSame([1.0, 2.0, 4.0], $this->sleeper->delays());
        }
    }

    public function testTheBudgetIsConfigurable(): void
    {
        $this->inner->willThrow(self::serverError(), 2)->willReturn(['ok' => true]);

        $this->expectException(ServerException::class);

        try {
            $this->middleware(maxRetries: 1)->request('GET', '/v1/sale-channels');
        } finally {
            self::assertSame(2, $this->inner->callCount(), 'One retry only, so the queued success is never reached.');
            self::assertSame([1.0], $this->sleeper->delays());
        }
    }

    public function testZeroRetriesDisablesTheMiddlewareEntirely(): void
    {
        $this->inner->willThrow(self::rateLimit(1));

        $this->expectException(RateLimitException::class);

        try {
            $this->middleware(maxRetries: 0)->request('GET', '/v1/sale-channels');
        } finally {
            self::assertSame(1, $this->inner->callCount());
            self::assertSame(0, $this->sleeper->slept());
        }
    }

    /**
     * The last sleep happens before the last attempt, never after it: giving up must not cost
     * the caller an extra wait.
     */
    /**
     * A per-wait ceiling promises nothing on its own: three consecutive `Retry-After: 60` are
     * each inside it and still block the worker for three minutes. The budget is what actually
     * bounds how long one call can hold a worker.
     */
    public function testTheTotalTimeSpentSleepingIsBounded(): void
    {
        $this->inner->willThrow(self::rateLimit(60), 3);

        try {
            $this->middleware(maxRetries: 3)->request('GET', '/v1/rma-requests');
            self::fail('The budget must stop the loop.');
        } catch (RateLimitException) {
            // expected
        }

        self::assertSame([60.0], $this->sleeper->delays(), 'One wait fits the 60s budget, a second does not.');
        self::assertLessThanOrEqual(
            RetryMiddleware::DEFAULT_MAX_TOTAL_DELAY,
            array_sum($this->sleeper->delays()),
        );
    }

    /**
     * Backoff is the SDK's own guess, so reaching the ceiling is no reason to stop trying.
     * Aborting there would make withRetry(8) quietly behave like withRetry(6).
     */
    public function testBackoffIsClampedToTheCeilingRatherThanAbortingTheLoop(): void
    {
        $this->inner->willThrow(self::serverError(), 4)->willReturn(['ok' => true]);

        $this->middleware(maxRetries: 8, maxDelay: 4.0, maxTotalDelay: 1000.0)
            ->request('GET', '/v1/rma-requests');

        self::assertSame(
            [1.0, 2.0, 4.0, 4.0],
            $this->sleeper->delays(),
            'The fourth wait is clamped to the ceiling, not treated as a reason to give up.',
        );
        self::assertSame(5, $this->inner->callCount());
    }

    /**
     * The opposite rule for a server instruction: sleeping less than asked would only hit the
     * same limit again and spend an attempt, so an over-long Retry-After is refused outright.
     */
    public function testAnOverLongRetryAfterIsRefusedRatherThanTrimmed(): void
    {
        $this->inner->willThrow(self::rateLimit(120));

        try {
            $this->middleware(maxRetries: 3, maxDelay: 60.0)->request('GET', '/v1/rma-requests');
            self::fail('An over-long Retry-After must not be slept through.');
        } catch (RateLimitException $exception) {
            self::assertSame(120, $exception->retryAfter(), 'retryAfter() survives for the caller to schedule on.');
        }

        self::assertSame([], $this->sleeper->delays());
    }

    public function testNoDelayIsSpentAfterTheFinalAttempt(): void
    {
        $this->inner->willThrow(self::serverError(), 3);

        try {
            $this->middleware(maxRetries: 2)->request('GET', '/v1/sale-channels');
        } catch (ServerException) {
            // expected
        }

        self::assertSame(3, $this->inner->callCount());
        self::assertCount(2, $this->sleeper->delays());
    }
}
