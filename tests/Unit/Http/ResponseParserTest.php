<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Unit\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RetJetApi\Returns\Exception\AccessDeniedException;
use RetJetApi\Returns\Exception\ApiException;
use RetJetApi\Returns\Exception\AuthenticationException;
use RetJetApi\Returns\Exception\MalformedResponseException;
use RetJetApi\Returns\Exception\NotFoundException;
use RetJetApi\Returns\Exception\Problem;
use RetJetApi\Returns\Exception\RateLimitException;
use RetJetApi\Returns\Exception\RetJetException;
use RetJetApi\Returns\Exception\ServerException;
use RetJetApi\Returns\Exception\ValidationException;
use RetJetApi\Returns\Http\ResponseParser;

#[CoversClass(ResponseParser::class)]
#[CoversClass(Problem::class)]
final class ResponseParserTest extends TestCase
{
    private ResponseParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ResponseParser();
    }

    /**
     * @param array<string, string> $headers
     */
    private function response(int $status, string $body = '', array $headers = []): ResponseInterface
    {
        $factory = new Psr17Factory();
        $response = $factory->createResponse($status)->withBody($factory->createStream($body));

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * @param array<array-key, mixed> $payload
     * @param array<string, string>   $headers
     */
    private function jsonResponse(int $status, array $payload, array $headers = []): ResponseInterface
    {
        return $this->response($status, (string) json_encode($payload), $headers);
    }

    /**
     * The request that supposedly produced the response being parsed. Its method and path are
     * what an ApiException reports, so most tests can use this default and the few that check
     * the exact message assert against the values fixed here.
     */
    private function request(string $method = 'GET', string $path = '/v1/rma-requests'): RequestInterface
    {
        return (new Psr17Factory())->createRequest($method, $path);
    }

    // ------------------------------------------------- header-driven pagination

    /**
     * This API paginates in headers, not in the body: the collection envelope carries no
     * `view`, and its `totalItems` counts the rows on the page rather than the whole set.
     * Measured against production: X-Total-Count said 118 while `totalItems` said 30 on every
     * page. A caller trusting the body stops paging where there is more to fetch.
     */
    public function testXTotalCountOutranksTheTotalItemsInTheBody(): void
    {
        $payload = $this->parser->parse(
            $this->jsonResponse(
                200,
                ['member' => [['id' => 1], ['id' => 2]], 'totalItems' => 2],
                ['X-Total-Count' => '118'],
            ),
            $this->request(),
        );

        self::assertSame(118, ResponseParser::unwrapCollection($payload)['totalItems']);
    }

    /**
     * Without this, Paginator asks for `view.next`, gets null on the first page and stops -
     * silently returning one page of a collection that has four.
     */
    public function testTheNextLinkHeaderBecomesTheHydraNextView(): void
    {
        $payload = $this->parser->parse(
            $this->jsonResponse(
                200,
                ['member' => [['id' => 1]]],
                ['Link' => '<https://api.example.com/v1/rma-requests?page=2>; rel="next"'],
            ),
            $this->request(),
        );

        self::assertSame(
            'https://api.example.com/v1/rma-requests?page=2',
            ResponseParser::unwrapCollection($payload)['view']['next'] ?? null,
        );
    }

    /**
     * The API advertises its documentation in the same header, and on the last page that is
     * the only link there. Taking any URL from Link rather than the one marked rel="next"
     * would make the paginator fetch the API documentation and treat it as the next page.
     */
    public function testALinkHeaderWithoutARelNextYieldsNoNextPage(): void
    {
        $payload = $this->parser->parse(
            $this->jsonResponse(
                200,
                ['member' => [['id' => 1]]],
                ['Link' => '<https://api.example.com/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"'],
            ),
            $this->request(),
        );

        self::assertArrayNotHasKey('next', ResponseParser::unwrapCollection($payload)['view']);
    }

    /**
     * rel="next" is picked out of a list, not assumed to come first.
     */
    public function testTheNextLinkIsFoundAmongOtherLinks(): void
    {
        $payload = $this->parser->parse(
            $this->jsonResponse(
                200,
                ['member' => [['id' => 1]]],
                ['Link' => '<https://api.example.com/v1/rma-requests?page=1>; rel="prev", '
                    . '<https://api.example.com/v1/rma-requests?page=3>; rel="next", '
                    . '<https://api.example.com/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"'],
            ),
            $this->request(),
        );

        self::assertSame(
            'https://api.example.com/v1/rma-requests?page=3',
            ResponseParser::unwrapCollection($payload)['view']['next'] ?? null,
        );
    }

    /**
     * A body that does carry a Hydra envelope keeps working: the headers fill gaps, they do
     * not overwrite a link the server actually sent.
     */
    public function testABodyViewSurvivesWhenNoNextLinkHeaderIsPresent(): void
    {
        $payload = $this->parser->parse(
            $this->jsonResponse(
                200,
                ['member' => [['id' => 1]], 'view' => ['next' => '/v1/rma-requests?page=2']],
            ),
            $this->request(),
        );

        self::assertSame('/v1/rma-requests?page=2', ResponseParser::unwrapCollection($payload)['view']['next']);
    }

    /**
     * A non-numeric header is ignored rather than cast: (int) "many" is 0, which would report
     * an empty collection for one that has rows.
     */
    public function testANonNumericTotalCountHeaderIsIgnored(): void
    {
        $payload = $this->parser->parse(
            $this->jsonResponse(
                200,
                ['member' => [['id' => 1], ['id' => 2]], 'totalItems' => 2],
                ['X-Total-Count' => 'many'],
            ),
            $this->request(),
        );

        self::assertSame(2, ResponseParser::unwrapCollection($payload)['totalItems']);
    }

    public function testItDecodesASuccessfulJsonObject(): void
    {
        $payload = $this->parser->parse(
            $this->jsonResponse(200, ['id' => 7, 'state' => ['name' => 'new']]),
            $this->request(),
        );

        self::assertSame(['id' => 7, 'state' => ['name' => 'new']], $payload);
    }

    public function testAnEmptyBodyDecodesToAnEmptyArray(): void
    {
        self::assertSame([], $this->parser->parse($this->response(204), $this->request()));
        self::assertSame([], $this->parser->parse($this->response(200, "  \n "), $this->request()));
    }

    /**
     * A success status with an unusable body is a protocol failure, not an API error, so it
     * must not arrive as an ApiException carrying a 2xx code - status-based branching and the
     * retry middleware both read that code.
     */
    public function testABodyThatIsNotJsonIsReported(): void
    {
        try {
            $this->parser->parse($this->response(200, '<html>oops</html>'), $this->request());
            self::fail('An unusable body must not be accepted.');
        } catch (RetJetException $exception) {
            // Caught as the marker interface on purpose: asserting the concrete class here
            // is what proves an ApiException carrying a 2xx status is not what came out.
            self::assertInstanceOf(MalformedResponseException::class, $exception);
            self::assertStringContainsString('not a JSON object', $exception->getMessage());
            self::assertSame(200, $exception->status());
        }
    }

    // -----------------------------------------------------------------------------------
    // Hydra
    // -----------------------------------------------------------------------------------

    public function testItUnwrapsAHydraCollection(): void
    {
        $payload = $this->parser->parse($this->jsonResponse(200, [
            '@context' => '/contexts/RmaRequest',
            '@id' => '/v1/rma-requests',
            '@type' => 'Collection',
            'member' => [
                ['@id' => '/v1/rma-requests/1', 'id' => 1],
                ['@id' => '/v1/rma-requests/2', 'id' => 2],
            ],
            'totalItems' => 57,
            'view' => [
                '@id' => '/v1/rma-requests?page=2',
                '@type' => 'PartialCollectionView',
                'first' => '/v1/rma-requests?page=1',
                'last' => '/v1/rma-requests?page=6',
                'previous' => '/v1/rma-requests?page=1',
                'next' => '/v1/rma-requests?page=3',
            ],
        ]), $this->request());

        $collection = ResponseParser::unwrapCollection($payload);

        self::assertCount(2, $collection['member']);
        self::assertSame(1, $collection['member'][0]['id']);
        self::assertSame(57, $collection['totalItems']);
        self::assertSame('/v1/rma-requests?page=3', $collection['view']['next']);
        self::assertSame('/v1/rma-requests?page=6', $collection['view']['last']);
    }

    public function testTotalItemsFallsBackToTheMemberCount(): void
    {
        $collection = ResponseParser::unwrapCollection(['member' => [['id' => 1]]]);

        self::assertSame(1, $collection['totalItems']);
        self::assertSame([], $collection['view']);
    }

    public function testAPayloadWithoutAMemberKeyUnwrapsToAnEmptyCollection(): void
    {
        $collection = ResponseParser::unwrapCollection(['id' => 1]);

        self::assertSame(['member' => [], 'totalItems' => 0, 'view' => []], $collection);
    }

    /**
     * Without Accept: application/ld+json the server answers with a bare array. Normalising
     * it into the Hydra envelope keeps a single collection shape for everything downstream,
     * even though the SDK never asks for that representation itself.
     */
    public function testABareJsonArrayIsNormalisedIntoTheHydraEnvelope(): void
    {
        $payload = $this->parser->parse($this->jsonResponse(200, [['id' => 1], ['id' => 2]]), $this->request());

        self::assertSame(2, $payload['totalItems']);

        $collection = ResponseParser::unwrapCollection($payload);

        self::assertCount(2, $collection['member']);
        self::assertSame([], $collection['view'], 'A bare array carries no pagination links.');
    }

    // -----------------------------------------------------------------------------------
    // Status mapping
    // -----------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{int, class-string<ApiException>}>
     */
    public static function statusMapping(): iterable
    {
        yield '401 unauthorized' => [401, AuthenticationException::class];
        yield '403 forbidden' => [403, AccessDeniedException::class];
        yield '404 not found' => [404, NotFoundException::class];
        yield '422 unprocessable' => [422, ValidationException::class];
        yield '429 too many requests' => [429, RateLimitException::class];
        yield '400 bad request' => [400, ApiException::class];
        yield '409 conflict' => [409, ApiException::class];
        yield '500 server error' => [500, ServerException::class];
        yield '503 unavailable' => [503, ServerException::class];
    }

    /**
     * @param class-string<ApiException> $expected
     */
    #[DataProvider('statusMapping')]
    public function testItMapsTheStatusToAnException(int $status, string $expected): void
    {
        try {
            $this->parser->parse($this->jsonResponse($status, [
                'title' => 'An error occurred',
                'detail' => 'Something went wrong',
                'status' => $status,
            ]), $this->request());
            self::fail(sprintf('Expected %s for status %d.', $expected, $status));
        } catch (ApiException $exception) {
            self::assertSame($expected, $exception::class);
            self::assertSame($status, $exception->status());
            self::assertSame('Something went wrong', $exception->problem()->detail());
        }
    }

    public function testTheOtherFourXxCaseIsNotOverriddenBySubclasses(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('HTTP 418: I am a teapot');

        $this->parser->parse($this->jsonResponse(418, ['detail' => 'I am a teapot']), $this->request());
    }

    /**
     * The exception carries the request that failed - not for the body or the query, which can
     * hold search terms or other caller-supplied data, but the method and path are safe to
     * surface and are exactly what an error tracker needs to tell one failing call from another.
     */
    public function testTheExceptionCarriesTheMethodAndPathOfTheFailedRequest(): void
    {
        try {
            $this->parser->parse(
                $this->jsonResponse(404, ['detail' => 'Not Found']),
                $this->request('DELETE', '/v1/rma-requests/1234/follower'),
            );
            self::fail('Expected a NotFoundException.');
        } catch (NotFoundException $exception) {
            self::assertSame('DELETE', $exception->method());
            self::assertSame('/v1/rma-requests/1234/follower', $exception->path());
            self::assertSame(
                'HTTP 404: Not Found (DELETE /v1/rma-requests/1234/follower)',
                $exception->getMessage(),
            );
        }
    }

    /**
     * A next link is followed as an absolute URL (Paginator does this verbatim), so the
     * request's URI carries a query string. It must not leak into the exception.
     */
    public function testTheQueryStringIsNotPartOfThePath(): void
    {
        try {
            $this->parser->parse(
                $this->jsonResponse(404, ['detail' => 'Not Found']),
                $this->request('GET', '/v1/rma-requests?page=3&email=someone%40example.com'),
            );
            self::fail('Expected a NotFoundException.');
        } catch (NotFoundException $exception) {
            self::assertSame('/v1/rma-requests', $exception->path());
            self::assertStringNotContainsString('email', $exception->getMessage());
        }
    }

    // -----------------------------------------------------------------------------------
    // Error payloads
    // -----------------------------------------------------------------------------------

    public function testItGroupsValidationViolationsByPropertyPath(): void
    {
        try {
            $this->parser->parse($this->jsonResponse(422, [
                'status' => 422,
                'detail' => "email: not valid\nemail: already used",
                'violations' => [
                    ['propertyPath' => 'email', 'message' => 'This value is not a valid email address.'],
                    ['propertyPath' => 'email', 'message' => 'This value is already used.'],
                    ['propertyPath' => 'items[0].quantity', 'message' => 'This value should be positive.'],
                    ['message' => 'The request is invalid.'],
                    ['propertyPath' => 'ignored'],
                    'not an object',
                ],
            ]), $this->request());
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertSame(
                [
                    'email' => ['This value is not a valid email address.', 'This value is already used.'],
                    'items[0].quantity' => ['This value should be positive.'],
                    '' => ['The request is invalid.'],
                ],
                $exception->violations(),
            );
            self::assertSame(['This value should be positive.'], $exception->violationsFor('items[0].quantity'));
        }
    }

    public function testAValidationResponseWithoutViolationsYieldsAnEmptyList(): void
    {
        try {
            $this->parser->parse($this->jsonResponse(422, ['detail' => 'Unprocessable']), $this->request());
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertSame([], $exception->violations());
        }
    }

    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function retryAfterHeaders(): iterable
    {
        yield 'seconds' => ['120', 120];
        yield 'zero' => ['0', 0];
        yield 'padded' => ['  45  ', 45];
        yield 'garbage' => ['soon', null];
    }

    #[DataProvider('retryAfterHeaders')]
    public function testItReadsTheRetryAfterHeader(string $header, ?int $expected): void
    {
        try {
            $this->parser->parse(
                $this->jsonResponse(429, ['detail' => 'Too Many Requests'], ['Retry-After' => $header]),
                $this->request(),
            );
            self::fail('Expected a RateLimitException.');
        } catch (RateLimitException $exception) {
            self::assertSame($expected, $exception->retryAfter());
        }
    }

    public function testRetryAfterIsNullWhenTheHeaderIsAbsent(): void
    {
        try {
            $this->parser->parse($this->jsonResponse(429, ['detail' => 'Too Many Requests']), $this->request());
            self::fail('Expected a RateLimitException.');
        } catch (RateLimitException $exception) {
            self::assertNull($exception->retryAfter());
        }
    }

    public function testItAcceptsAnHttpDateInRetryAfter(): void
    {
        $date = gmdate('D, d M Y H:i:s \G\M\T', time() + 90);

        try {
            $this->parser->parse(
                $this->jsonResponse(429, [], ['Retry-After' => $date]),
                $this->request(),
            );
            self::fail('Expected a RateLimitException.');
        } catch (RateLimitException $exception) {
            $retryAfter = $exception->retryAfter();

            self::assertNotNull($retryAfter);
            self::assertGreaterThanOrEqual(88, $retryAfter);
            self::assertLessThanOrEqual(90, $retryAfter);
        }
    }

    /**
     * The dev environment appends trace[] to every error body. It must be ignored, and the
     * response must still map on status alone.
     */
    public function testItIgnoresTheTraceMemberOnErrorResponses(): void
    {
        try {
            $this->parser->parse($this->jsonResponse(404, [
                'title' => 'An error occurred',
                'detail' => 'Not Found',
                'status' => 404,
                'type' => '/errors/404',
                'trace' => [['file' => '/app/src/Kernel.php', 'line' => 12]],
            ]), $this->request());
            self::fail('Expected a NotFoundException.');
        } catch (NotFoundException $exception) {
            self::assertSame('Not Found', $exception->problem()->detail());
            self::assertArrayNotHasKey('trace', $exception->problem()->toArray());
        }
    }

    /**
     * Known server quirk: an invalid API key answers 500 "Unable to exchange token" instead
     * of 401. The SDK refuses to guess from the message, so this stays a ServerException -
     * but problem()->detail() has to make the cause visible.
     */
    public function testAnInvalidApiKeyStaysAServerExceptionAndExposesTheDetail(): void
    {
        try {
            $this->parser->parse($this->jsonResponse(500, [
                'title' => 'An error occurred',
                'detail' => 'Unable to exchange token',
                'status' => 500,
                'trace' => [['file' => '/app/src/Security/TokenExchanger.php', 'line' => 88]],
            ]), $this->request());
            self::fail('Expected a ServerException.');
        } catch (ApiException $exception) {
            self::assertSame(ServerException::class, $exception::class, 'The 500 must not be rewritten as a 401.');
            self::assertSame(500, $exception->status());
            self::assertSame('Unable to exchange token', $exception->problem()->detail());
            self::assertSame('HTTP 500: Unable to exchange token (GET /v1/rma-requests)', $exception->getMessage());
        }
    }

    public function testAMissingKeyStillProducesAuthenticationExceptionOn401(): void
    {
        try {
            $this->parser->parse($this->jsonResponse(401, [
                'title' => 'An error occurred',
                'detail' => 'Full authentication is required to access this resource.',
                'status' => 401,
            ]), $this->request());
            self::fail('Expected an AuthenticationException.');
        } catch (AuthenticationException $exception) {
            self::assertSame(401, $exception->status());
        }
    }

    /**
     * The production host currently serves an HTML 404 for every /v1 path. A non-JSON error
     * body must not blow up the parser.
     */
    public function testANonJsonErrorBodyStillMapsOnStatus(): void
    {
        try {
            $this->parser->parse(
                $this->response(404, '<!doctype html><title>Not Found</title>'),
                $this->request(),
            );
            self::fail('Expected a NotFoundException.');
        } catch (NotFoundException $exception) {
            self::assertSame(404, $exception->status());
            self::assertNull($exception->problem()->detail());
            self::assertSame(404, $exception->problem()->status(), 'Falls back to the HTTP status.');
            self::assertSame(
                'HTTP 404: RetJet API request failed (GET /v1/rma-requests)',
                $exception->getMessage(),
            );
        }
    }
}
