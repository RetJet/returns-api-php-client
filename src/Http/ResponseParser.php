<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RetJetApi\Returns\Exception\AccessDeniedException;
use RetJetApi\Returns\Exception\ApiException;
use RetJetApi\Returns\Exception\AuthenticationException;
use RetJetApi\Returns\Exception\MalformedResponseException;
use RetJetApi\Returns\Exception\NotFoundException;
use RetJetApi\Returns\Exception\Problem;
use RetJetApi\Returns\Exception\RateLimitException;
use RetJetApi\Returns\Exception\ServerException;
use RetJetApi\Returns\Exception\ValidationException;

/**
 * Decodes responses and turns error statuses into the SDK exception hierarchy.
 *
 * @internal
 */
final class ResponseParser
{
    /**
     * Reads a successful response body as a JSON object.
     *
     * An empty body (204, and the write endpoints that answer with no content) decodes to
     * an empty array. A bare top-level JSON array - what the server returns for a collection
     * when `Accept: application/ld+json` was not sent - is normalised into the Hydra envelope
     * so that callers only ever see one collection shape.
     *
     * $request is the one that produced $response - it is never sent anywhere, only read for
     * the method and path an ApiException carries so a >= 400 status is traceable to the call
     * that caused it.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException             on any status >= 400
     * @throws MalformedResponseException when a success status carries a body that is not usable JSON
     */
    public function parse(ResponseInterface $response, RequestInterface $request): array
    {
        $body = self::readBody($response);
        $status = $response->getStatusCode();

        if ($status >= 400) {
            throw $this->toException($response, $body, $request);
        }

        if (trim($body) === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw MalformedResponseException::notJson($status);
        }

        if (array_is_list($decoded)) {
            return ['member' => $decoded, 'totalItems' => count($decoded)];
        }

        return self::withStringKeys($decoded);
    }

    /**
     * Unwraps a Hydra collection envelope into the three parts the collection layer needs.
     *
     * Pure, so the resource layer can call it on an already decoded payload without holding
     * a parser instance.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{member: list<array<string, mixed>>, totalItems: int, view: array<string, string>}
     */
    public static function unwrapCollection(array $payload): array
    {
        $member = [];
        $raw = $payload['member'] ?? null;

        if (is_array($raw)) {
            foreach ($raw as $item) {
                if (is_array($item)) {
                    $member[] = self::withStringKeys($item);
                }
            }
        }

        $totalItems = $payload['totalItems'] ?? null;

        $view = [];
        $rawView = $payload['view'] ?? null;

        if (is_array($rawView)) {
            foreach ($rawView as $key => $value) {
                if (is_string($value)) {
                    $view[(string) $key] = $value;
                }
            }
        }

        return [
            'member' => $member,
            'totalItems' => is_int($totalItems) ? $totalItems : count($member),
            'view' => $view,
        ];
    }

    /**
     * The status alone decides the exception; the body is only ever read for detail.
     * Nothing here inspects the message text - notably the token-exchange-failure case, which
     * the API reports as 500 "Unable to exchange token" and which a bad key can also trigger,
     * stays a ServerException on purpose.
     */
    private function toException(ResponseInterface $response, string $body, RequestInterface $request): ApiException
    {
        $status = $response->getStatusCode();
        $payload = self::decodeQuietly($body);
        $problem = Problem::fromArray($payload, $status);
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        return match (true) {
            $status === 401 => new AuthenticationException($status, $problem, $method, $path),
            $status === 403 => new AccessDeniedException($status, $problem, $method, $path),
            $status === 404 => new NotFoundException($status, $problem, $method, $path),
            $status === 422 => new ValidationException($status, $problem, self::extractViolations($payload), $method, $path),
            $status === 429 => new RateLimitException($status, $problem, self::parseRetryAfter($response), $method, $path),
            $status >= 500 => new ServerException($status, $problem, $method, $path),
            default => new ApiException($status, $problem, $method, $path),
        };
    }

    /**
     * Groups the flat `violations[]` list by property path. Entries without a usable
     * message are dropped; entries without a property path land under the empty key.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, list<string>>
     */
    private static function extractViolations(array $payload): array
    {
        $violations = $payload['violations'] ?? null;

        if (!is_array($violations)) {
            return [];
        }

        $grouped = [];

        foreach ($violations as $violation) {
            if (!is_array($violation)) {
                continue;
            }

            $message = $violation['message'] ?? null;

            if (!is_string($message)) {
                continue;
            }

            $path = $violation['propertyPath'] ?? null;
            $key = is_string($path) ? $path : '';

            $grouped[$key][] = $message;
        }

        return $grouped;
    }

    /**
     * `Retry-After` is either a delay in seconds or an HTTP date; both are reduced to
     * a number of seconds from now.
     */
    private static function parseRetryAfter(ResponseInterface $response): ?int
    {
        $header = trim($response->getHeaderLine('Retry-After'));

        if ($header === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $header) === 1) {
            return (int) $header;
        }

        $timestamp = strtotime($header);

        if ($timestamp === false) {
            return null;
        }

        return max(0, $timestamp - time());
    }

    /**
     * Error bodies are best-effort: the API answers `application/problem+json`, but a
     * misrouted request can still return HTML. A body that will not decode simply yields
     * an empty payload and therefore a Problem with null members.
     *
     * @return array<string, mixed>
     */
    private static function decodeQuietly(string $body): array
    {
        $decoded = json_decode($body, true);

        if (!is_array($decoded) || array_is_list($decoded)) {
            return [];
        }

        return self::withStringKeys($decoded);
    }

    /**
     * JSON object keys that look like integers decode to PHP integer keys; normalising them
     * back to strings is what makes the array<string, mixed> return types honest.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function withStringKeys(array $data): array
    {
        $normalised = [];

        foreach ($data as $key => $value) {
            $normalised[(string) $key] = $value;
        }

        return $normalised;
    }

    private static function readBody(ResponseInterface $response): string
    {
        $stream = $response->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $stream->getContents();
    }
}
