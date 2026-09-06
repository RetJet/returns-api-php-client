<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;
use RetJetApi\Returns\Client;
use RetJetApi\Returns\ClientBuilder;
use RetJetApi\Returns\Configuration;
use RetJetApi\Returns\Exception\ConfigurationException;
use RetJetApi\Returns\Http\Transport;
use RetJetApi\Returns\Tests\Support\Fixtures;
use RetJetApi\Returns\Tests\Support\MockHttpClient;

#[CoversClass(Client::class)]
#[CoversClass(ClientBuilder::class)]
final class ClientBuilderTest extends TestCase
{
    private MockHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
    }

    /**
     * The headline promise: an API key is the whole configuration.
     */
    public function testCreateNeedsNothingButTheApiKey(): void
    {
        $configuration = Client::create('secret-key')->configuration();

        self::assertSame('secret-key', $configuration->apiKey);
        self::assertSame('https://api.retjet.com', $configuration->baseUri);
        self::assertSame(3, $configuration->maxRetries);
        self::assertSame(10, $configuration->timeout);
    }

    /**
     * End to end on a stub: no baseUri anywhere, and the request still lands on production.
     */
    public function testCreateReachesProductionWithoutAnyBaseUri(): void
    {
        $this->http->willRespondWithJson(200, Fixtures::hydraCollection([Fixtures::load('sale_channel')]));

        $page = Client::create('secret-key', $this->http)->saleChannels()->list();

        self::assertSame(
            'https://api.retjet.com/v1/sale-channels?page=1',
            (string) $this->http->lastRequest()->getUri(),
        );
        self::assertSame('My Shopify Store', $page->member()[0]->label);
    }

    /**
     * withBaseUri has to redirect every request, not just the first one.
     */
    public function testWithBaseUriOverridesTheHostEverywhere(): void
    {
        $this->http
            ->willRespondWithJson(200, Fixtures::hydraCollection([Fixtures::load('sale_channel')]))
            ->willRespondWithJson(200, Fixtures::load('sale_channel'))
            ->willRespondWithJson(200, Fixtures::hydraCollection([Fixtures::load('return_point')]))
            ->willRespondWithJson(200, Fixtures::hydraCollection([Fixtures::load('item_reason')]));

        $client = Client::builder()
            ->withApiKey('secret-key')
            ->withBaseUri('https://api.example.com')
            ->withHttpClient($this->http)
            ->build();

        $client->saleChannels()->list();
        $client->saleChannels()->get(1);
        $client->returnPoints()->list();
        iterator_to_array($client->itemReasons()->iterate(), false);

        foreach ($this->http->requests() as $request) {
            self::assertSame('api.example.com', $request->getUri()->getHost());
        }

        self::assertSame(4, $this->http->requestCount());
    }

    public function testTheBuilderRefusesToBuildWithoutAnApiKey(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('An API key is required');

        Client::builder()->build();
    }

    public function testEverySettingIsOptionalAndOverridable(): void
    {
        $configuration = Client::builder()
            ->withApiKey('k')
            ->withBaseUri('https://api.example.com/')
            ->withRetry(0)
            ->withUserAgent('my-app/2.0')
            ->withHttpClient($this->http)
            ->build()
            ->configuration();

        self::assertSame('https://api.example.com', $configuration->baseUri);
        self::assertSame(0, $configuration->maxRetries, 'withRetry(0) switches retrying off.');
        self::assertSame('my-app/2.0', $configuration->userAgent);
    }

    public function testTheUserAgentOverrideReachesTheWire(): void
    {
        $this->http->willRespondWithJson(200, Fixtures::hydraCollection([]));

        Client::builder()
            ->withApiKey('k')
            ->withUserAgent('my-app/2.0')
            ->withHttpClient($this->http)
            ->build()
            ->saleChannels()
            ->list();

        self::assertSame('my-app/2.0', $this->http->lastRequest()->getHeaderLine('User-Agent'));
    }

    // ------------------------------------------------------------------------- timeout

    /**
     * A timeout can only be applied while the HTTP client is being constructed. When the
     * caller supplies the client, the SDK has no way to honour withTimeout() - so it refuses
     * the combination instead of accepting it and doing nothing.
     */
    public function testWithTimeoutIsRejectedAlongsideACallerSuppliedClient(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Configure the timeout on the client you pass to withHttpClient()');

        Client::builder()
            ->withApiKey('k')
            ->withHttpClient($this->http)
            ->withTimeout(30)
            ->build();
    }

    public function testTheOrderOfTheTwoCallsDoesNotMatter(): void
    {
        $this->expectException(ConfigurationException::class);

        Client::builder()
            ->withApiKey('k')
            ->withTimeout(30)
            ->withHttpClient($this->http)
            ->build();
    }

    /**
     * Without a caller-supplied client the SDK builds one itself, which is what makes the
     * timeout mean something.
     */
    public function testWithTimeoutIsAcceptedWhenTheSdkBuildsTheClient(): void
    {
        $configuration = Client::builder()->withApiKey('k')->withTimeout(30)->build()->configuration();

        self::assertSame(30, $configuration->timeout);
    }

    public function testACallerSuppliedClientAloneIsFine(): void
    {
        $configuration = Client::builder()->withApiKey('k')->withHttpClient($this->http)->build()->configuration();

        self::assertSame(Configuration::DEFAULT_TIMEOUT, $configuration->timeout);
    }

    // ------------------------------------------------------------------------- accessors

    public function testTheClientCarriesTheLoggerUntilTheMiddlewareArrives(): void
    {
        $logger = new NullLogger();

        $client = Client::builder()->withApiKey('k')->withLogger($logger)->withHttpClient($this->http)->build();

        self::assertSame($logger, $client->logger());
        self::assertNull(Client::create('k', $this->http)->logger());
    }

    public function testTheTransportIsReachableForEndpointsTheSdkDoesNotWrapYet(): void
    {
        $this->http->willRespondWithJson(200, ['ok' => true]);

        $client = Client::create('k', $this->http);

        self::assertInstanceOf(Transport::class, $client->transport());
        self::assertSame(['ok' => true], $client->transport()->request('GET', '/v1/anything'));
    }

    public function testEveryResourceAccessorIsWiredToTheSameTransport(): void
    {
        $this->http
            ->willRespondWithJson(200, Fixtures::hydraCollection([]))
            ->willRespondWithJson(200, Fixtures::hydraCollection([]))
            ->willRespondWithJson(200, Fixtures::hydraCollection([]))
            ->willRespondWithJson(200, Fixtures::hydraCollection([]))
            ->willRespondWithJson(200, Fixtures::hydraCollection([]))
            ->willRespondWithJson(200, Fixtures::hydraCollection([]))
            ->willRespondWithJson(200, Fixtures::hydraCollection([]));

        $client = Client::create('k', $this->http);

        $client->rmaRequests()->list();
        $client->saleChannels()->list();
        $client->returnPoints()->list();
        $client->orderedProducts()->list();
        $client->itemConditions()->list();
        $client->itemReasons()->list();
        $client->itemResolutions()->list();

        self::assertSame([
            'https://api.retjet.com/v1/rma-requests?page=1',
            'https://api.retjet.com/v1/sale-channels?page=1',
            'https://api.retjet.com/v1/return-points?page=1',
            'https://api.retjet.com/v1/ordered-products?page=1',
            'https://api.retjet.com/v1/rma-request-items-conditions?page=1',
            'https://api.retjet.com/v1/rma-request-items-reasons?page=1',
            'https://api.retjet.com/v1/rma-request-items-resolutions?page=1',
        ], array_map(
            static fn (RequestInterface $r): string => (string) $r->getUri(),
            $this->http->requests(),
        ));
    }
}
