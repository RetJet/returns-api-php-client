<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Unit\Http\Middleware;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LogLevel;
use RetJetApi\Returns\Client;
use RetJetApi\Returns\Configuration;
use RetJetApi\Returns\Exception\NotFoundException;
use RetJetApi\Returns\Exception\Problem;
use RetJetApi\Returns\Exception\TransportException;
use RetJetApi\Returns\Http\Middleware\LoggingMiddleware;
use RetJetApi\Returns\Http\RequestBuilder;
use RetJetApi\Returns\Tests\Support\Fixtures;
use RetJetApi\Returns\Tests\Support\MockHttpClient;
use RetJetApi\Returns\Tests\Support\RecordingLogger;
use RetJetApi\Returns\Tests\Support\RecordingTransport;
use RuntimeException;

#[CoversClass(LoggingMiddleware::class)]
final class LoggingMiddlewareTest extends TestCase
{
    private const API_KEY = 'sk_test_51H8xQ2eZvKYlo2C0RETJETEXAMPLE';

    private RecordingTransport $inner;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->inner = new RecordingTransport();
        $this->logger = new RecordingLogger();
    }

    private function middleware(): LoggingMiddleware
    {
        return new LoggingMiddleware($this->inner, $this->logger);
    }

    // ------------------------------------------------------------------- what is recorded

    public function testASuccessfulCallIsRecordedTwice(): void
    {
        $this->inner->willReturn(['id' => 1]);

        self::assertSame(['id' => 1], $this->middleware()->request('get', '/v1/rma-requests', ['page' => 2]));

        $records = $this->logger->records();

        self::assertCount(2, $records);
        self::assertSame(['RetJet API request', 'RetJet API response'], $this->logger->messages());
        self::assertSame([LogLevel::DEBUG, LogLevel::DEBUG], [$records[0]['level'], $records[1]['level']]);

        self::assertSame('GET', $records[0]['context']['method'], 'The method is normalised.');
        self::assertSame('/v1/rma-requests', $records[0]['context']['path']);
        self::assertSame(['page' => 2], $records[0]['context']['query']);
        self::assertArrayHasKey('duration_ms', $records[1]['context']);
    }

    public function testAFailureIsRecordedAtTheErrorLevelAndRethrown(): void
    {
        $failure = new NotFoundException(404, new Problem(null, null, 404, 'Not Found'));
        $this->inner->willThrow($failure);

        try {
            $this->middleware()->request('GET', '/v1/rma-requests/9999');
            self::fail('Expected a NotFoundException.');
        } catch (NotFoundException $exception) {
            self::assertSame($failure, $exception, 'The exception is passed through untouched.');
        }

        $records = $this->logger->records();

        self::assertCount(2, $records);
        self::assertSame(LogLevel::WARNING, $records[1]['level']);
        self::assertSame('RetJet API request failed', $records[1]['message']);
        self::assertSame(NotFoundException::class, $records[1]['context']['exception']);
        self::assertSame(404, $records[1]['context']['status']);
        self::assertSame('HTTP 404: Not Found', $records[1]['context']['error']);
    }

    /**
     * A transport failure never had a status, so the field is present and null rather than
     * missing - a log consumer should not have to branch on the key existing.
     */
    public function testATransportFailureIsRecordedWithoutAStatus(): void
    {
        $this->inner->willThrow(new TransportException('Connection refused'));

        try {
            $this->middleware()->request('GET', '/v1/sale-channels');
        } catch (TransportException) {
            // expected
        }

        $context = $this->logger->recordsAtLevel(LogLevel::WARNING)[0]['context'];

        self::assertArrayHasKey('status', $context);
        self::assertNull($context['status']);
        self::assertSame(TransportException::class, $context['exception']);
    }

    public function testTheLevelsAreConfigurable(): void
    {
        $this->inner->willReturn([]);

        $middleware = new LoggingMiddleware($this->inner, $this->logger, LogLevel::INFO, LogLevel::ERROR);
        $middleware->request('GET', '/v1/sale-channels');

        self::assertSame([LogLevel::INFO, LogLevel::INFO], array_map(
            static fn (array $record): string => $record['level'],
            $this->logger->records(),
        ));
    }

    // ------------------------------------------------------------------- masking

    /**
     * @return iterable<string, array{string}>
     */
    public static function sensitiveHeaders(): iterable
    {
        yield 'Authorization' => ['Authorization'];
        yield 'lower case' => ['authorization'];
        yield 'upper case' => ['AUTHORIZATION'];
        yield 'Proxy-Authorization' => ['Proxy-Authorization'];
        yield 'Cookie' => ['Cookie'];
        yield 'X-Api-Key' => ['X-Api-Key'];
        yield 'X-Auth-Token' => ['X-Auth-Token'];
    }

    #[DataProvider('sensitiveHeaders')]
    public function testASensitiveHeaderIsMaskedWhateverItsCasing(string $header): void
    {
        $this->inner->willReturn([]);

        $this->middleware()->request('GET', '/v1/sale-channels', [], null, [$header => 'Bearer ' . self::API_KEY]);

        $headers = $this->logger->records()[0]['context']['headers'];

        self::assertSame([$header => LoggingMiddleware::REDACTED], $headers);

        foreach ($this->logger->flattened() as $entry) {
            self::assertStringNotContainsString(self::API_KEY, $entry);
        }
    }

    public function testHarmlessHeadersAreKept(): void
    {
        $this->inner->willReturn([]);

        $this->middleware()->request('GET', '/v1/sale-channels', [], null, [
            'X-Request-Id' => 'abc-123',
            'Accept' => 'application/json',
        ]);

        self::assertSame(
            ['X-Request-Id' => 'abc-123', 'Accept' => 'application/json'],
            $this->logger->records()[0]['context']['headers'],
        );
    }

    /**
     * Bodies carry a customer's e-mail, address and bank account number. None of that belongs
     * in a debug log, so neither the request payload nor the response payload is recorded.
     */
    public function testBodiesAreNeverLogged(): void
    {
        $this->inner->willReturn(Fixtures::load('rma_request'));

        $this->middleware()->request('POST', '/v1/rma-requests', [], [
            'refundBankAccountNo' => 'PL61109010140000071219812874',
            'customer' => ['email' => 'john.doe@example.com'],
        ]);

        foreach ($this->logger->flattened() as $entry) {
            self::assertStringNotContainsString('PL61109010140000071219812874', $entry);
            self::assertStringNotContainsString('john.doe@example.com', $entry);
        }

        foreach ($this->logger->records() as $record) {
            self::assertArrayNotHasKey('body', $record['context']);
            self::assertArrayNotHasKey('payload', $record['context']);
            self::assertArrayNotHasKey('response', $record['context']);
        }
    }

    // ------------------------------------------------------------------- the hard guarantee

    /**
     * The API key must not appear in any record, anywhere - not in a message, not in a context
     * key, not nested inside a context value. Every record written during a realistic session
     * is flattened and searched, so this fails if any future change starts logging the request
     * builder's headers, the configuration or an exception that quotes them.
     */
    public function testTheApiKeyNeverAppearsInAnyLogRecord(): void
    {
        $http = new MockHttpClient();
        $http
            ->willRespondWithJson(200, Fixtures::hydraCollection(
                [Fixtures::load('sale_channel')],
                2,
                ['next' => '/v1/sale-channels?page=2'],
            ))
            ->willRespondWithJson(200, Fixtures::hydraCollection([Fixtures::load('sale_channel')], 2))
            ->willRespondWithJson(404, ['detail' => 'Not Found', 'status' => 404])
            ->willFail('Connection refused')
            ->willRespondWithJson(200, ['ok' => true]);

        $client = Client::builder()
            ->withApiKey(self::API_KEY)
            ->withHttpClient($http)
            ->withLogger($this->logger)
            ->withRetry(0)
            ->build();

        iterator_to_array($client->saleChannels()->iterate(), false);

        try {
            $client->saleChannels()->get(9999);
        } catch (NotFoundException) {
            // expected
        }

        try {
            $client->saleChannels()->list();
        } catch (TransportException) {
            // expected
        }

        // A caller pushing a credential through the per-request header overrides must be
        // masked too, even though the SDK's own Authorization header never reaches this layer.
        $client->transport()->request('GET', '/v1/anything', [], null, [
            'Authorization' => 'Bearer ' . self::API_KEY,
        ]);

        $flattened = $this->logger->flattened();

        self::assertNotSame([], $flattened, 'The logger must actually have recorded something.');

        foreach ($flattened as $entry) {
            self::assertStringNotContainsString(
                self::API_KEY,
                $entry,
                'The API key leaked into a log record.',
            );
        }

        self::assertNotSame([], $this->logger->recordsAtLevel(LogLevel::WARNING), 'Failures were recorded too.');
    }

    /**
     * Every attempt of a retried call is logged, which is the reason logging sits inside
     * retrying rather than outside it.
     */
    public function testEachRetriedAttemptProducesItsOwnRecords(): void
    {
        $http = new MockHttpClient();
        $http
            ->willRespondWithJson(429, ['detail' => 'Too Many Requests'], ['Retry-After' => '0'])
            ->willRespondWithJson(429, ['detail' => 'Too Many Requests'], ['Retry-After' => '0'])
            ->willRespondWithJson(200, Fixtures::hydraCollection([]));

        Client::builder()
            ->withApiKey(self::API_KEY)
            ->withHttpClient($http)
            ->withLogger($this->logger)
            ->build()
            ->saleChannels()
            ->list();

        self::assertSame(3, $http->requestCount());
        self::assertCount(2, $this->logger->recordsAtLevel(LogLevel::WARNING), 'Both rejected attempts are visible.');
        self::assertCount(4, $this->logger->recordsAtLevel(LogLevel::DEBUG), 'Three requests plus the one response.');
    }

    /**
     * Masking headers is worthless if the same secret rides in an exception message.
     * TransportException embeds the request URI, and a base URI may carry credentials.
     */
    public function testCredentialsInTheBaseUriNeverReachTheLog(): void
    {
        $factory = new Psr17Factory();
        $configuration = new Configuration('secret-key', 'https://svc:s3cret@api.example.com');
        $request = (new RequestBuilder($configuration, $factory, $factory))->build('GET', '/v1/sale-channels');

        $clientFailure = new class ('refused') extends RuntimeException implements ClientExceptionInterface {};
        $transport = new RecordingTransport();
        $transport->willThrow(TransportException::fromClientException($clientFailure, $request));

        $logger = new RecordingLogger();

        try {
            (new LoggingMiddleware($transport, $logger))->request('GET', '/v1/sale-channels');
            self::fail('The failure must propagate.');
        } catch (TransportException) {
            // expected
        }

        foreach ($logger->flattened() as $entry) {
            self::assertStringNotContainsString('s3cret', $entry, 'A password leaked into a log record.');
            self::assertStringNotContainsString('svc:', $entry, 'The userinfo component leaked into a log record.');
        }

        self::assertNotSame([], $logger->flattened(), 'The test would pass vacuously on an empty log.');
    }
}
