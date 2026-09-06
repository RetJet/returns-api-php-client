<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Unit\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RetJetApi\Returns\Configuration;
use RetJetApi\Returns\Exception\NotFoundException;
use RetJetApi\Returns\Exception\RetJetException;
use RetJetApi\Returns\Exception\ServerException;
use RetJetApi\Returns\Exception\TransportException;
use RetJetApi\Returns\Http\PsrTransport;
use RetJetApi\Returns\Http\RequestBuilder;
use RetJetApi\Returns\Http\ResponseParser;
use RetJetApi\Returns\Http\Transport;
use RetJetApi\Returns\Tests\Support\MockClientException;
use RetJetApi\Returns\Tests\Support\MockHttpClient;

#[CoversClass(PsrTransport::class)]
final class PsrTransportTest extends TestCase
{
    private MockHttpClient $client;

    private Transport $transport;

    protected function setUp(): void
    {
        $factory = new Psr17Factory();
        $this->client = new MockHttpClient();
        $this->transport = new PsrTransport(
            $this->client,
            new RequestBuilder(new Configuration('secret-key', 'https://api.example.com'), $factory, $factory),
            new ResponseParser(),
        );
    }

    public function testItSendsTheBuiltRequestAndReturnsTheDecodedBody(): void
    {
        $this->client->willRespondWithJson(200, ['@id' => '/v1/rma-requests/7', 'id' => 7]);

        $payload = $this->transport->request('GET', '/v1/rma-requests/7');

        self::assertSame(7, $payload['id']);

        $request = $this->client->lastRequest();

        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://api.example.com/v1/rma-requests/7', (string) $request->getUri());
        self::assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));
        self::assertSame('application/ld+json', $request->getHeaderLine('Accept'));
    }

    public function testItPassesQueryBodyAndExtraHeadersThrough(): void
    {
        $this->client->willRespondWithJson(201, ['id' => 1]);

        $this->transport->request(
            'POST',
            '/v1/rma-requests',
            ['page' => 2],
            ['comment' => 'zwrot'],
            ['X-Request-Id' => 'abc'],
        );

        $request = $this->client->lastRequest();

        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.example.com/v1/rma-requests?page=2', (string) $request->getUri());
        self::assertSame('{"comment":"zwrot"}', (string) $request->getBody());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('abc', $request->getHeaderLine('X-Request-Id'));
    }

    /**
     * What the paginator will do in stage 3: hand a Hydra view link straight back to the
     * transport instead of rebuilding the URL.
     */
    public function testAnAbsoluteUrlIsRequestedVerbatim(): void
    {
        $this->client->willRespondWithJson(200, ['member' => [], 'totalItems' => 0]);

        $this->transport->request('GET', 'https://api.example.com/v1/rma-requests?page=4');

        self::assertSame(
            'https://api.example.com/v1/rma-requests?page=4',
            (string) $this->client->lastRequest()->getUri(),
        );
    }

    public function testANoContentResponseYieldsAnEmptyPayload(): void
    {
        $this->client->willRespond(204);

        self::assertSame([], $this->transport->request('DELETE', '/v1/rma-requests/1/star'));
    }

    public function testAPsr18FailureBecomesATransportException(): void
    {
        $cause = new MockClientException('Connection refused');
        $this->client->willThrow($cause);

        try {
            $this->transport->request('GET', '/v1/sale-channels');
            self::fail('Expected a TransportException.');
        } catch (TransportException $exception) {
            self::assertSame($cause, $exception->getPrevious());
            self::assertStringContainsString('GET https://api.example.com/v1/sale-channels', $exception->getMessage());
            self::assertStringContainsString('Connection refused', $exception->getMessage());
            self::assertInstanceOf(RetJetException::class, $exception);
        }
    }

    public function testAnErrorStatusIsMappedBeforeItReachesTheCaller(): void
    {
        $this->client->willRespondWithJson(404, ['detail' => 'Not Found', 'status' => 404]);

        $this->expectException(NotFoundException::class);

        $this->transport->request('GET', '/v1/rma-requests/9999');
    }

    public function testTheInvalidKeyQuirkSurvivesTheWholeTransportPath(): void
    {
        $this->client->willRespondWithJson(500, [
            'title' => 'An error occurred',
            'detail' => 'Unable to exchange token',
            'status' => 500,
            'trace' => [['file' => '/app/src/Kernel.php', 'line' => 1]],
        ]);

        try {
            $this->transport->request('GET', '/v1/sale-channels');
            self::fail('Expected a ServerException.');
        } catch (ServerException $exception) {
            self::assertSame('Unable to exchange token', $exception->problem()->detail());
        }
    }

    public function testEachCallConsumesOneQueuedResponse(): void
    {
        $this->client->willRespondWithJson(200, ['id' => 1])->willRespondWithJson(200, ['id' => 2]);

        self::assertSame(1, $this->transport->request('GET', '/v1/rma-requests/1')['id']);
        self::assertSame(2, $this->transport->request('GET', '/v1/rma-requests/2')['id']);
        self::assertSame(2, $this->client->requestCount());
    }
}
