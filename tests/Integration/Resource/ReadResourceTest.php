<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Integration\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RetJetApi\Returns\Client;
use RetJetApi\Returns\Configuration;
use RetJetApi\Returns\Exception\NotFoundException;
use RetJetApi\Returns\Resource\ItemConditions;
use RetJetApi\Returns\Resource\ItemReasons;
use RetJetApi\Returns\Resource\ItemResolutions;
use RetJetApi\Returns\Resource\OrderedProducts;
use RetJetApi\Returns\Resource\ReturnPoints;
use RetJetApi\Returns\Resource\SaleChannels;
use RetJetApi\Returns\Tests\Support\Fixtures;
use RetJetApi\Returns\Tests\Support\MockHttpClient;

/**
 * Every read resource, end to end over a stubbed PSR-18 client: the path it targets, the
 * headers it sends and the models it produces.
 *
 * The paths are asserted as literals on purpose. The item path is the collection path plus
 * an id today (`/v1/sale-channels` and `/v1/sale-channels/{id}`), but it was a separate
 * singular segment until 2026-09, and a client that derives the URL from a convention rather
 * than stating it goes silently to the wrong place the next time that changes.
 */
#[CoversClass(SaleChannels::class)]
#[CoversClass(ReturnPoints::class)]
#[CoversClass(OrderedProducts::class)]
#[CoversClass(ItemConditions::class)]
#[CoversClass(ItemReasons::class)]
#[CoversClass(ItemResolutions::class)]
final class ReadResourceTest extends TestCase
{
    private MockHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
    }

    private function client(string $baseUri = 'https://api.example.com'): Client
    {
        return Client::builder()
            ->withApiKey('secret-key')
            ->withBaseUri($baseUri)
            ->withHttpClient($this->http)
            ->build();
    }

    /**
     * @param list<array<string, mixed>> $member
     */
    private function queueCollection(array $member, ?int $totalItems = null): void
    {
        $this->http->willRespondWithJson(200, Fixtures::hydraCollection($member, $totalItems));
    }

    private function assertGetWasSentTo(string $uri): void
    {
        $request = $this->http->lastRequest();

        self::assertSame('GET', $request->getMethod());
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));
        self::assertSame(
            'application/ld+json',
            $request->getHeaderLine('Accept'),
            'Collections are only paginable in the JSON-LD representation.',
        );
        self::assertSame(Configuration::defaultUserAgent(), $request->getHeaderLine('User-Agent'));
    }

    // --------------------------------------------------------------------- SaleChannels

    public function testSaleChannelsList(): void
    {
        $this->queueCollection([Fixtures::load('sale_channel')], 3);

        $page = $this->client()->saleChannels()->list();

        $this->assertGetWasSentTo('https://api.example.com/v1/sale-channels?page=1');
        self::assertCount(1, $page);
        self::assertSame(3, $page->totalItems());
        self::assertSame('My Shopify Store', $page->member()[0]->label);
        self::assertSame('shopify', $page->member()[0]->channelType);
    }

    public function testSaleChannelsListPage(): void
    {
        $this->queueCollection([]);

        $this->client()->saleChannels()->list(page: 5);

        $this->assertGetWasSentTo('https://api.example.com/v1/sale-channels?page=5');
    }

    public function testSaleChannelsGetUsesTheSingularPath(): void
    {
        $this->http->willRespondWithJson(200, Fixtures::load('sale_channel'));

        $channel = $this->client()->saleChannels()->get(1);

        $this->assertGetWasSentTo('https://api.example.com/v1/sale-channels/1');
        self::assertSame(1, $channel->id);
        self::assertSame(154, $channel->returnPointId);
        self::assertNotNull($channel->returnPointAddress);
        self::assertSame('Warsaw Warehouse', $channel->returnPointAddress->customLabel);
    }

    public function testSaleChannelsIterateWalksEveryPage(): void
    {
        $this->http
            ->willRespondWithJson(200, Fixtures::hydraCollection(
                [Fixtures::load('sale_channel')],
                2,
                ['next' => '/v1/sale-channels?page=2'],
            ))
            ->willRespondWithJson(200, Fixtures::hydraCollection([Fixtures::load('sale_channel')], 2));

        $labels = [];

        foreach ($this->client()->saleChannels()->iterate() as $channel) {
            $labels[] = $channel->label;
        }

        self::assertSame(['My Shopify Store', 'My Shopify Store'], $labels);
        self::assertSame([
            'https://api.example.com/v1/sale-channels',
            'https://api.example.com/v1/sale-channels?page=2',
        ], array_map(
            static fn (RequestInterface $r): string => (string) $r->getUri(),
            $this->http->requests(),
        ));
    }

    // --------------------------------------------------------------------- ReturnPoints

    public function testReturnPointsList(): void
    {
        $this->queueCollection([Fixtures::load('return_point')]);

        $page = $this->client()->returnPoints()->list();

        $this->assertGetWasSentTo('https://api.example.com/v1/return-points?page=1');
        self::assertSame('Warsaw Warehouse', $page->member()[0]->customLabel);
    }

    public function testReturnPointsGetUsesTheSingularPath(): void
    {
        $this->http->willRespondWithJson(200, Fixtures::load('return_point'));

        $point = $this->client()->returnPoints()->get(10);

        $this->assertGetWasSentTo('https://api.example.com/v1/return-points/10');
        self::assertSame(10, $point->id);
        self::assertSame('returns@acme.com', $point->contactEmail);
    }

    // --------------------------------------------------------------------- OrderedProducts

    public function testOrderedProductsList(): void
    {
        $this->queueCollection([Fixtures::load('ordered_product')]);

        $page = $this->client()->orderedProducts()->list();

        $this->assertGetWasSentTo('https://api.example.com/v1/ordered-products?page=1');
        self::assertSame('Premium Wireless Headphones', $page->member()[0]->name);
        self::assertSame(299.99, $page->member()[0]->price);
    }

    /**
     * The API publishes no `/v1/ordered-product/{id}` operation, so the SDK must not offer
     * one either.
     */
    public function testOrderedProductsHasNoItemMethod(): void
    {
        self::assertNotContains('get', get_class_methods(OrderedProducts::class));
        self::assertContains('list', get_class_methods(OrderedProducts::class));
        self::assertContains('iterate', get_class_methods(OrderedProducts::class));
    }

    // --------------------------------------------------------------------- dictionaries

    public function testItemConditionsList(): void
    {
        $this->queueCollection([Fixtures::load('item_condition')]);

        $page = $this->client()->itemConditions()->list();

        $this->assertGetWasSentTo('https://api.example.com/v1/rma-request-items-conditions?page=1');
        self::assertSame('new_unopened', $page->member()[0]->label);
    }

    public function testItemConditionsGetUsesTheSingularPath(): void
    {
        $this->http->willRespondWithJson(200, Fixtures::load('item_condition'));

        $condition = $this->client()->itemConditions()->get(1);

        $this->assertGetWasSentTo('https://api.example.com/v1/rma-request-items-conditions/1');
        self::assertSame('New / Unopened', $condition->labelTranslated);
    }

    public function testItemReasonsList(): void
    {
        $this->queueCollection([Fixtures::load('item_reason')]);

        $page = $this->client()->itemReasons()->list();

        $this->assertGetWasSentTo('https://api.example.com/v1/rma-request-items-reasons?page=1');
        self::assertSame('defective', $page->member()[0]->label);
    }

    public function testItemReasonsGetUsesTheSingularPath(): void
    {
        $this->http->willRespondWithJson(200, Fixtures::load('item_reason'));

        $reason = $this->client()->itemReasons()->get(1);

        $this->assertGetWasSentTo('https://api.example.com/v1/rma-request-items-reasons/1');
        self::assertSame('Product is defective', $reason->labelTranslated);
    }

    public function testItemResolutionsList(): void
    {
        $this->queueCollection([Fixtures::load('item_resolution')]);

        $page = $this->client()->itemResolutions()->list();

        $this->assertGetWasSentTo('https://api.example.com/v1/rma-request-items-resolutions?page=1');
        self::assertSame('refund', $page->member()[0]->label);
    }

    public function testItemResolutionsGetUsesTheSingularPath(): void
    {
        $this->http->willRespondWithJson(200, Fixtures::load('item_resolution'));

        $resolution = $this->client()->itemResolutions()->get(1);

        $this->assertGetWasSentTo('https://api.example.com/v1/rma-request-items-resolutions/1');
        self::assertSame('Full refund', $resolution->labelTranslated);
    }

    // --------------------------------------------------------------------- error paths

    public function testAnErrorStatusSurfacesAsTheMappedException(): void
    {
        $this->http->willRespondWithJson(404, ['detail' => 'Not Found', 'status' => 404]);

        $this->expectException(NotFoundException::class);

        $this->client()->saleChannels()->get(9999);
    }
}
