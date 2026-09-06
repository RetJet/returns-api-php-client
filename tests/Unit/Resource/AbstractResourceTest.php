<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Unit\Resource;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RetJetApi\Returns\Collection\Paginator;
use RetJetApi\Returns\Configuration;
use RetJetApi\Returns\Exception\ConfigurationException;
use RetJetApi\Returns\Http\PsrTransport;
use RetJetApi\Returns\Http\RequestBuilder;
use RetJetApi\Returns\Http\ResponseParser;
use RetJetApi\Returns\Http\Transport;
use RetJetApi\Returns\Resource\AbstractResource;
use RetJetApi\Returns\Tests\Support\Fixtures;
use RetJetApi\Returns\Tests\Support\MockHttpClient;
use RetJetApi\Returns\Tests\Support\TestResource;

#[CoversClass(AbstractResource::class)]
final class AbstractResourceTest extends TestCase
{
    private MockHttpClient $http;

    private Transport $transport;

    protected function setUp(): void
    {
        $factory = new Psr17Factory();
        $this->http = new MockHttpClient();
        $this->transport = new PsrTransport(
            $this->http,
            new RequestBuilder(new Configuration('secret-key', 'https://api.example.com'), $factory, $factory),
            new ResponseParser(),
        );
    }

    private function resource(?string $itemPath = '/v1/sale-channels/{id}'): TestResource
    {
        return new TestResource($this->transport, $itemPath);
    }

    public function testFetchSendsAGet(): void
    {
        $this->http->willRespondWithJson(200, ['id' => 1]);

        self::assertSame(['id' => 1], $this->resource()->callFetch('/v1/anything', ['page' => 2]));

        $request = $this->http->lastRequest();

        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://api.example.com/v1/anything?page=2', (string) $request->getUri());
        self::assertSame('', (string) $request->getBody());
    }

    public function testPostPutAndDeleteSendTheirVerbAndBody(): void
    {
        $this->http
            ->willRespondWithJson(201, ['id' => 1])
            ->willRespondWithJson(200, ['id' => 1])
            ->willRespond(204);

        $resource = $this->resource();
        $resource->callPost('/v1/things', ['a' => 1]);
        $resource->callPut('/v1/things/1', ['b' => 2]);
        $resource->callDelete('/v1/things/1');

        $requests = $this->http->requests();

        self::assertSame('POST', $requests[0]->getMethod());
        self::assertSame('{"a":1}', (string) $requests[0]->getBody());
        self::assertSame('application/json', $requests[0]->getHeaderLine('Content-Type'));

        self::assertSame('PUT', $requests[1]->getMethod());
        self::assertSame('{"b":2}', (string) $requests[1]->getBody());

        self::assertSame('DELETE', $requests[2]->getMethod());
        self::assertSame('', (string) $requests[2]->getBody(), 'A bodyless DELETE stays bodyless.');
    }

    /**
     * A write with no fields still has to be a JSON object - several action endpoints in
     * stage 5 take no payload at all.
     */
    public function testAPostWithoutAPayloadSendsAnEmptyJsonObject(): void
    {
        $this->http->willRespond(204);

        $this->resource()->callPost('/v1/rma-requests/1/star');

        self::assertSame('{}', (string) $this->http->lastRequest()->getBody());
    }

    public function testItemFillsTheIdPlaceholderAndHydrates(): void
    {
        $this->http->willRespondWithJson(200, Fixtures::load('sale_channel'));

        $channel = $this->resource()->callItem(7);

        self::assertSame('https://api.example.com/v1/sale-channels/7', (string) $this->http->lastRequest()->getUri());
        self::assertSame('My Shopify Store', $channel->label);
    }

    public function testItemUrlEncodesTheId(): void
    {
        $this->http->willRespondWithJson(200, Fixtures::load('sale_channel'));

        $this->resource('/v1/sale-channels/{id}')->callItem('a b/c');

        self::assertSame(
            'https://api.example.com/v1/sale-channels/a%20b%2Fc',
            (string) $this->http->lastRequest()->getUri(),
        );
    }

    /**
     * OrderedProduct has no item endpoint. A resource that declares none
     * has to say so loudly rather than build a URL the API does not serve.
     */
    public function testAskingForAnItemOnACollectionOnlyResourceFails(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('has no item endpoint');

        $this->resource(null)->callItem(1);
    }

    public function testCollectionAlwaysSendsThePageParameter(): void
    {
        $this->http
            ->willRespondWithJson(200, Fixtures::hydraCollection([Fixtures::load('sale_channel')], 12))
            ->willRespondWithJson(200, Fixtures::hydraCollection([], 12));

        $resource = $this->resource();
        $page = $resource->callCollection();
        $resource->callCollection(4);

        $requests = $this->http->requests();

        self::assertSame('https://api.example.com/v1/sale-channels?page=1', (string) $requests[0]->getUri());
        self::assertSame('https://api.example.com/v1/sale-channels?page=4', (string) $requests[1]->getUri());
        self::assertSame(12, $page->totalItems());
        self::assertCount(1, $page);
    }

    /**
     * collection() takes page as a named argument, so a stray `page` inside $query must lose
     * to it rather than silently win or be dropped without trace. paginate() has no such
     * argument, so there `page` legitimately sets the starting page.
     */
    public function testThePageArgumentBeatsAPageInsideTheQuery(): void
    {
        $this->http
            ->willRespondWithJson(200, Fixtures::hydraCollection([], 0))
            ->willRespondWithJson(200, Fixtures::hydraCollection([], 0));

        $resource = $this->resource();
        $resource->callCollection(4, ['page' => 99, 'keep' => 'yes']);
        iterator_to_array($resource->callPaginate(['page' => 7]), false);

        $requests = $this->http->requests();

        self::assertSame(
            'https://api.example.com/v1/sale-channels?page=4&keep=yes',
            (string) $requests[0]->getUri(),
        );
        self::assertSame(
            'https://api.example.com/v1/sale-channels?page=7',
            (string) $requests[1]->getUri(),
        );
    }

    /**
     * The paginator lets the server pick the first page and then follows view.next, so it
     * sends no page parameter of its own.
     */
    public function testPaginateSendsNoQueryByDefaultAndIsLazy(): void
    {
        $this->http->willRespondWithJson(200, Fixtures::hydraCollection([Fixtures::load('sale_channel')], 1));

        $paginator = $this->resource()->callPaginate();

        self::assertInstanceOf(Paginator::class, $paginator);
        self::assertSame(0, $this->http->requestCount(), 'Building a paginator sends nothing.');

        self::assertCount(1, iterator_to_array($paginator, false));
        self::assertSame('https://api.example.com/v1/sale-channels', (string) $this->http->lastRequest()->getUri());
    }

    public function testEveryRequestCarriesTheSdkHeaders(): void
    {
        $this->http->willRespondWithJson(200, ['id' => 1]);

        $this->resource()->callFetch('/v1/anything');

        $request = $this->http->lastRequest();

        self::assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));
        self::assertSame('application/ld+json', $request->getHeaderLine('Accept'));
        self::assertSame(Configuration::defaultUserAgent(), $request->getHeaderLine('User-Agent'));
    }
}
