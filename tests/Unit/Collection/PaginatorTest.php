<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Unit\Collection;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RetJetApi\Returns\Collection\Paginator;
use RetJetApi\Returns\Collection\ResourceCollection;
use RetJetApi\Returns\Configuration;
use RetJetApi\Returns\Exception\ConfigurationException;
use RetJetApi\Returns\Http\PsrTransport;
use RetJetApi\Returns\Http\RequestBuilder;
use RetJetApi\Returns\Http\ResponseParser;
use RetJetApi\Returns\Http\Transport;
use RetJetApi\Returns\Model\RmaRequest;
use RetJetApi\Returns\Tests\Support\Fixtures;
use RetJetApi\Returns\Tests\Support\MockHttpClient;

#[CoversClass(Paginator::class)]
final class PaginatorTest extends TestCase
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

    /**
     * The exploit this guards against: `view.next` is data the server controls, and every
     * request the paginator makes carries `Authorization: Bearer <apiKey>`. A `next` link
     * pointing at another host would hand the API key to that host on the next iteration.
     */
    public function testASpoofedNextLinkNeverReceivesTheApiKey(): void
    {
        $this->client->willRespondWithJson(200, [
            'member' => [Fixtures::load('rma_request')],
            'totalItems' => 99,
            'view' => ['next' => 'https://evil.example/v1/rma-requests?page=2'],
        ]);

        $paginator = new Paginator($this->transport, '/v1/rma-requests', RmaRequest::class);

        $consumed = [];

        try {
            foreach ($paginator as $rma) {
                $consumed[] = $rma;
            }
            self::fail('The paginator must refuse to follow a next link off the base URI.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('would be disclosed', $exception->getMessage());
        }

        self::assertCount(1, $consumed, 'The first, legitimate page is still yielded.');
        self::assertCount(1, $this->client->requests(), 'No second request was ever sent.');

        foreach ($this->client->requests() as $request) {
            self::assertStringStartsWith(
                'https://api.example.com/',
                (string) $request->getUri(),
                'The API key must never leave the configured origin.',
            );
        }
    }

    private function queueThreePages(): void
    {
        $this->client
            ->willRespondWithJson(200, Fixtures::load('rma_requests_page_1'))
            ->willRespondWithJson(200, Fixtures::load('rma_requests_page_2'))
            ->willRespondWithJson(200, Fixtures::load('rma_requests_page_3'));
    }

    /**
     * @return Paginator<RmaRequest>
     */
    private function paginator(string $path = '/v1/rma-requests'): Paginator
    {
        return new Paginator($this->transport, $path, RmaRequest::class);
    }

    public function testNothingIsFetchedUntilTheIteratorIsAdvanced(): void
    {
        $this->queueThreePages();

        $paginator = $this->paginator();
        $items = $paginator->getIterator();

        self::assertSame(0, $this->client->requestCount(), 'Constructing and obtaining the generator are free.');

        self::assertInstanceOf(RmaRequest::class, $items->current());
        self::assertSame(1, $this->client->requestCount());
    }

    public function testItWalksEveryPageInOrder(): void
    {
        $this->queueThreePages();

        $ids = [];

        foreach ($this->paginator() as $rma) {
            $ids[] = $rma->id;
        }

        self::assertSame([1234, 1235, 1236, 1237, 1238], $ids);
        self::assertSame(3, $this->client->requestCount());
    }

    /**
     * The point of the paginator: page N+1 is fetched only once the consumer has worked
     * through page N. Consuming the two items of page 1 and stopping must cost one request.
     */
    public function testTheNextPageIsNotFetchedUntilTheCurrentOneIsExhausted(): void
    {
        $this->queueThreePages();

        $seen = 0;

        foreach ($this->paginator() as $rma) {
            self::assertNotNull($rma->id);
            ++$seen;

            if ($seen === 2) {
                break;
            }
        }

        self::assertSame(2, $seen);
        self::assertSame(1, $this->client->requestCount(), 'Page 2 must not have been fetched.');
    }

    public function testReachingIntoTheSecondPageFetchesExactlyTwoPages(): void
    {
        $this->queueThreePages();

        $seen = 0;

        foreach ($this->paginator() as $rma) {
            self::assertNotNull($rma->id);

            if (++$seen === 3) {
                break;
            }
        }

        self::assertSame(2, $this->client->requestCount());
    }

    /**
     * The next page is requested through the server's own view.next link, which the
     * transport accepts as-is.
     */
    public function testItFollowsTheHydraViewNextLink(): void
    {
        $this->queueThreePages();

        iterator_to_array($this->paginator(), false);

        $uris = array_map(
            static fn (RequestInterface $request): string => (string) $request->getUri(),
            $this->client->requests(),
        );

        self::assertSame([
            'https://api.example.com/v1/rma-requests',
            'https://api.example.com/v1/rma-requests?page=2',
            'https://api.example.com/v1/rma-requests?page=3',
        ], $uris);
    }

    public function testASinglePageCollectionStopsAfterOneRequest(): void
    {
        $this->client->willRespondWithJson(200, Fixtures::load('rma_requests_single_page'));

        $ids = [];

        foreach ($this->paginator() as $rma) {
            $ids[] = $rma->id;
        }

        self::assertSame([1234], $ids);
        self::assertSame(1, $this->client->requestCount());
    }

    public function testAnEmptyCollectionYieldsNothing(): void
    {
        $this->client->willRespondWithJson(200, ['member' => [], 'totalItems' => 0]);

        self::assertSame([], iterator_to_array($this->paginator(), false));
        self::assertSame(1, $this->client->requestCount());
    }

    /**
     * pages() is for consumers that need the page metadata, e.g. totalItems, and it stays
     * just as lazy as iterating the items.
     */
    public function testPagesYieldsCollectionsLazily(): void
    {
        $this->queueThreePages();

        $sizes = [];

        foreach ($this->paginator()->pages() as $page) {
            self::assertInstanceOf(ResourceCollection::class, $page);
            self::assertSame(5, $page->totalItems());
            $sizes[] = $page->count();
        }

        self::assertSame([2, 2, 1], $sizes);
        self::assertSame(3, $this->client->requestCount());
    }

    public function testTakingOnlyTheFirstPageCostsOneRequest(): void
    {
        $this->queueThreePages();

        foreach ($this->paginator()->pages() as $page) {
            self::assertSame(2, $page->count());

            break;
        }

        self::assertSame(1, $this->client->requestCount());
    }

    /**
     * The initial query applies to the first request only - re-applying it to a view.next
     * link would overwrite the page number the server just handed us and loop forever.
     */
    public function testTheInitialQueryIsNotReappliedToFollowedLinks(): void
    {
        $this->client
            ->willRespondWithJson(200, Fixtures::load('rma_requests_page_2'))
            ->willRespondWithJson(200, Fixtures::load('rma_requests_page_3'));

        $paginator = new Paginator($this->transport, '/v1/rma-requests', RmaRequest::class, ['page' => 2]);

        iterator_to_array($paginator, false);

        $uris = array_map(
            static fn (RequestInterface $request): string => (string) $request->getUri(),
            $this->client->requests(),
        );

        self::assertSame([
            'https://api.example.com/v1/rma-requests?page=2',
            'https://api.example.com/v1/rma-requests?page=3',
        ], $uris);
    }

    /**
     * A view.next pointing back at the page just fetched would spin forever. API Platform
     * does not emit one, but a rewriting proxy might.
     */
    public function testASelfReferencingNextLinkStopsTheWalk(): void
    {
        $page = [
            'member' => [['id' => 1]],
            'totalItems' => 2,
            'view' => ['next' => '/v1/rma-requests?page=2'],
        ];

        $this->client
            ->willRespondWithJson(200, $page)
            ->willRespondWithJson(200, $page)
            ->willRespondWithJson(200, $page);

        $ids = [];

        foreach ($this->paginator() as $rma) {
            $ids[] = $rma->id;
        }

        self::assertSame([1, 1], $ids);
        self::assertSame(2, $this->client->requestCount(), 'The second page points at itself, so the walk ends.');
    }

    /**
     * A -> B -> A is a longer cycle than the self-referencing case above: the offending link
     * does not point at the page just fetched, only at one fetched earlier. A check against
     * the immediately preceding URL alone would miss it and spin forever.
     */
    public function testALongerCycleThroughAnEarlierPageStopsTheWalk(): void
    {
        $pageA = [
            'member' => [['id' => 1]],
            'totalItems' => 3,
            'view' => ['next' => '/v1/rma-requests?page=2'],
        ];
        $pageB = [
            'member' => [['id' => 2]],
            'totalItems' => 3,
            'view' => ['next' => '/v1/rma-requests'],
        ];

        $this->client
            ->willRespondWithJson(200, $pageA)
            ->willRespondWithJson(200, $pageB)
            ->willRespondWithJson(200, $pageA)
            ->willRespondWithJson(200, $pageB);

        $ids = [];

        foreach ($this->paginator() as $rma) {
            $ids[] = $rma->id;
        }

        self::assertSame([1, 2], $ids);
        self::assertSame(2, $this->client->requestCount(), 'Page A recurs, so the walk ends without refetching it.');
    }

    /**
     * A proxy that rewrites every next link to a URL never seen before would defeat the
     * visited-URL check above, since nothing ever repeats. The walk still has to end.
     */
    public function testAnEndlessSupplyOfNovelNextLinksStopsAtTheHardPageLimit(): void
    {
        $totalPages = 10_000;

        for ($page = 1; $page <= $totalPages; ++$page) {
            $this->client->willRespondWithJson(200, [
                'member' => [['id' => $page]],
                'totalItems' => $totalPages + 1,
                'view' => ['next' => sprintf('/v1/rma-requests?page=%d', $page + 1)],
            ]);
        }

        $ids = [];

        foreach ($this->paginator() as $rma) {
            $ids[] = $rma->id;
        }

        self::assertSame(range(1, $totalPages), $ids);
        self::assertSame($totalPages, $this->client->requestCount());
    }
}
