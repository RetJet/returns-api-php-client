<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Http\Middleware;

use Closure;
use RetJetApi\Returns\Configuration;
use RetJetApi\Returns\Exception\RateLimitException;
use RetJetApi\Returns\Exception\RetJetException;
use RetJetApi\Returns\Exception\ServerException;
use RetJetApi\Returns\Exception\TransportException;
use RetJetApi\Returns\Http\Transport;

/**
 * Retries the failures that are worth retrying, and only on the requests where a retry cannot
 * do damage.
 *
 * ## What is retried, and why it depends on the method
 *
 * | Failure                              | GET, PUT, DELETE | POST, PATCH |
 * |--------------------------------------|------------------|-------------|
 * | 429 RateLimitException               | yes              | **yes**     |
 * | 5xx ServerException                  | yes              | no          |
 * | network TransportException           | yes              | no          |
 * | anything else (4xx, malformed body)  | no               | no          |
 *
 * A 429 is answered by the rate limiter before the request reaches the handler, so the
 * request was rejected rather than processed and replaying it is safe whatever the method is.
 *
 * A 5xx is the opposite: the server took the request and then failed somewhere. A 500 out of
 * `POST .../message` may well mean the message was created and the response blew up
 * afterwards, and retrying would post it twice. A transport error is worse still - the
 * request may have been fully processed and only the response lost - and PSR-18 flattens
 * "connection refused" (provably safe to replay) and "read timeout after the server acted"
 * (not safe) into the same ClientExceptionInterface, so the two cannot be told apart without
 * sniffing a concrete client's exception classes. Both therefore retry only where RFC 9110
 * guarantees idempotence, which holds for this API's own endpoints: PUT product sets the same
 * values twice, DELETE owner/star/follower removes something already removed.
 *
 * The practical consequence is that retrying barely touches writes in this API, since almost
 * every write is a POST. That is the intended outcome: a duplicated RMA request or a
 * double-applied status change is a worse failure than an error the caller can see.
 *
 * ## Timing
 *
 * Exponential backoff from $baseDelay, doubling per attempt (1s, 2s, 4s by default), and
 * **clamped** to $maxDelay. Clamping rather than giving up matters: a computed backoff is our
 * own guess, so hitting the ceiling is no reason to stop trying, and aborting there would make
 * withRetry(8) quietly behave like withRetry(6).
 *
 * A 429 carrying Retry-After uses the server's number instead - it beats a guess. That one is
 * *not* clamped: if the server asks for longer than $maxDelay, the exception is rethrown with
 * retryAfter() intact, because sleeping less than instructed would only hit the same limit
 * again and burn an attempt. The caller can schedule the work instead.
 *
 * $maxTotalDelay bounds the sum of every wait inside a single request() call. Without it the
 * per-wait ceiling promises nothing: three consecutive `Retry-After: 60` are each within the
 * ceiling and still block the worker for three minutes. When the next wait would cross the
 * budget the exception is rethrown rather than partially slept.
 *
 * No jitter is added, so the delays stay assertable in tests. A fleet that needs it can
 * supply a $sleeper that jitters the delay it is handed.
 */
final class RetryMiddleware implements MiddlewareInterface
{
    public const DEFAULT_BASE_DELAY = 1.0;
    public const DEFAULT_MAX_DELAY = 60.0;
    public const DEFAULT_MAX_TOTAL_DELAY = 60.0;

    /**
     * Methods RFC 9110 guarantees idempotent, which is what makes replaying them safe.
     */
    private const IDEMPOTENT_METHODS = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS', 'TRACE'];

    /** @var Closure(float): void */
    private readonly Closure $sleeper;

    /**
     * @param int                        $maxRetries how many *extra* attempts to make, so 3
     *                                               means up to four requests in total and 0
     *                                               disables retrying altogether
     * @param (callable(float): void)|null $sleeper  seconds to wait; injectable so tests do
     *                                               not actually sleep
     * @param float                      $maxDelay      ceiling for a single wait
     * @param float                      $maxTotalDelay ceiling for every wait in one call,
     *                                                  added together
     */
    public function __construct(
        private readonly Transport $next,
        private readonly int $maxRetries = Configuration::DEFAULT_MAX_RETRIES,
        ?callable $sleeper = null,
        private readonly float $baseDelay = self::DEFAULT_BASE_DELAY,
        private readonly float $maxDelay = self::DEFAULT_MAX_DELAY,
        private readonly float $maxTotalDelay = self::DEFAULT_MAX_TOTAL_DELAY,
    ) {
        $default = static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };

        $chosen = $sleeper ?? $default;

        $this->sleeper = static function (float $seconds) use ($chosen): void {
            $chosen($seconds);
        };
    }

    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        array $headers = [],
    ): array {
        $attempt = 0;
        $slept = 0.0;

        while (true) {
            try {
                return $this->next->request($method, $path, $query, $body, $headers);
            } catch (RetJetException $exception) {
                if ($attempt >= $this->maxRetries) {
                    throw $exception;
                }

                $delay = $this->delayFor($exception, $method, $attempt);

                // Never sleep a fraction of what was asked for: a shortened wait would hit the
                // same condition again and spend an attempt for nothing.
                if ($delay === null || $slept + $delay > $this->maxTotalDelay) {
                    throw $exception;
                }

                ($this->sleeper)($delay);
                $slept += $delay;
                ++$attempt;
            }
        }
    }

    /**
     * How long to wait before replaying, or null when this failure must not be replayed.
     */
    private function delayFor(RetJetException $exception, string $method, int $attempt): ?float
    {
        // Rejected at the gate, never processed: safe to replay whatever the method was.
        if ($exception instanceof RateLimitException) {
            $retryAfter = $exception->retryAfter();

            if ($retryAfter === null) {
                return $this->backoff($attempt);
            }

            // The server's instruction is obeyed or refused, never trimmed.
            return $retryAfter > $this->maxDelay ? null : (float) $retryAfter;
        }

        if (!self::isIdempotent($method)) {
            return null;
        }

        if ($exception instanceof ServerException || $exception instanceof TransportException) {
            return $this->backoff($attempt);
        }

        return null;
    }

    /**
     * Our own guess, so it is clamped rather than treated as a reason to stop.
     */
    private function backoff(int $attempt): float
    {
        return min($this->baseDelay * (2 ** $attempt), $this->maxDelay);
    }

    private static function isIdempotent(string $method): bool
    {
        return in_array(strtoupper($method), self::IDEMPOTENT_METHODS, true);
    }
}
