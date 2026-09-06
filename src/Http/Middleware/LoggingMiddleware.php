<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Http\Middleware;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RetJetApi\Returns\Exception\ApiException;
use RetJetApi\Returns\Exception\RetJetException;
use RetJetApi\Returns\Http\Redact;
use RetJetApi\Returns\Http\Transport;

/**
 * Writes one record per HTTP call to a PSR-3 logger.
 *
 * ## What is deliberately not logged
 *
 * **Credentials.** Any header whose name is in SENSITIVE_HEADERS is replaced with `***`.
 * At this layer the Authorization header the SDK itself sends is not even visible - the API
 * key lives in Configuration and RequestBuilder attaches it further down - but a caller can
 * pass one through the per-request header overrides, and that path has to be closed too.
 *
 * **Request and response bodies.** An RmaRequest carries a customer's e-mail, postal address
 * and `refundBankAccountNo`; a CreateRmaRequest payload carries the same. Writing those into
 * a debug log copies personal and banking data into a system with different retention and
 * access rules than the API has, usually without anyone deciding to. Bodies are therefore
 * never logged, at any level, and there is no flag to turn them on: the useful part of an
 * HTTP log is which call was made and how it went, and that is what gets recorded.
 *
 * **The exception object.** Failures are logged as class name, message and status rather than
 * as a Throwable under the conventional `exception` key. The exception is rethrown untouched,
 * so the application's own error handler still gets the full object with its stack trace;
 * duplicating it here would only put a second, harder-to-serialise copy in the audit trail.
 */
final class LoggingMiddleware implements MiddlewareInterface
{
    /**
     * Lower-cased header names whose values never reach the log.
     */
    public const SENSITIVE_HEADERS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'x-auth-token',
    ];

    public const REDACTED = '***';

    /**
     * @param string $level      level for the request and response records
     * @param string $errorLevel level for the failure record
     */
    public function __construct(
        private readonly Transport $next,
        private readonly LoggerInterface $logger,
        private readonly string $level = LogLevel::DEBUG,
        private readonly string $errorLevel = LogLevel::WARNING,
    ) {
    }

    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        array $headers = [],
    ): array {
        $context = [
            'method' => strtoupper($method),
            'path' => $path,
            'query' => $query,
            'headers' => self::mask($headers),
        ];

        $this->logger->log($this->level, 'RetJet API request', $context);

        $startedAt = microtime(true);

        try {
            $payload = $this->next->request($method, $path, $query, $body, $headers);
        } catch (RetJetException $exception) {
            $this->logger->log($this->errorLevel, 'RetJet API request failed', [
                'method' => $context['method'],
                'path' => $path,
                'duration_ms' => self::elapsed($startedAt),
                'exception' => $exception::class,
                'error' => Redact::credentials($exception->getMessage()),
                'status' => $exception instanceof ApiException ? $exception->status() : null,
            ]);

            throw $exception;
        }

        $this->logger->log($this->level, 'RetJet API response', [
            'method' => $context['method'],
            'path' => $path,
            'duration_ms' => self::elapsed($startedAt),
        ]);

        return $payload;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private static function mask(array $headers): array
    {
        $masked = [];

        foreach ($headers as $name => $value) {
            $masked[$name] = in_array(strtolower($name), self::SENSITIVE_HEADERS, true)
                ? self::REDACTED
                : $value;
        }

        return $masked;
    }

    private static function elapsed(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 2);
    }
}
