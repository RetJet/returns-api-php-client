<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Unit\Collection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RetJetApi\Returns\Collection\ResourceCollection;
use RetJetApi\Returns\Model\RmaRequest;
use RetJetApi\Returns\Model\SaleChannel;
use RetJetApi\Returns\Tests\Support\Fixtures;

#[CoversClass(ResourceCollection::class)]
final class ResourceCollectionTest extends TestCase
{
    public function testItHydratesTheMembersOfAHydraPage(): void
    {
        $collection = ResourceCollection::fromPayload(Fixtures::load('rma_requests_page_1'), RmaRequest::class);

        self::assertCount(2, $collection);
        self::assertContainsOnlyInstancesOf(RmaRequest::class, $collection->member());
        self::assertSame([1234, 1235], array_map(static fn (RmaRequest $r): ?int => $r->id, $collection->member()));
    }

    /**
     * count() is the page size and totalItems() the size of the whole result set. Getting
     * these two mixed up is the classic pagination bug, so they are asserted together.
     */
    public function testCountIsThePageSizeAndTotalItemsTheWholeSet(): void
    {
        $collection = ResourceCollection::fromPayload(Fixtures::load('rma_requests_page_1'), RmaRequest::class);

        self::assertSame(2, $collection->count());
        self::assertSame(5, $collection->totalItems());
    }

    public function testItExposesTheHydraViewLinks(): void
    {
        $collection = ResourceCollection::fromPayload(Fixtures::load('rma_requests_page_2'), RmaRequest::class);

        self::assertSame('/v1/rma-requests?page=1', $collection->view()['first']);
        self::assertSame('/v1/rma-requests?page=3', $collection->view()['last']);
        self::assertSame('/v1/rma-requests?page=1', $collection->previousPage());
        self::assertSame('/v1/rma-requests?page=3', $collection->nextPage());
        self::assertTrue($collection->hasNextPage());
    }

    public function testTheLastPageHasNoNextLink(): void
    {
        $collection = ResourceCollection::fromPayload(Fixtures::load('rma_requests_page_3'), RmaRequest::class);

        self::assertNull($collection->nextPage());
        self::assertFalse($collection->hasNextPage());
        self::assertSame('/v1/rma-requests?page=2', $collection->previousPage());
    }

    public function testASinglePageCollectionHasNeitherNextNorPrevious(): void
    {
        $collection = ResourceCollection::fromPayload(Fixtures::load('rma_requests_single_page'), RmaRequest::class);

        self::assertNull($collection->nextPage());
        self::assertNull($collection->previousPage());
        self::assertSame(1, $collection->totalItems());
    }

    public function testItIsIterable(): void
    {
        $collection = ResourceCollection::fromPayload(Fixtures::load('rma_requests_page_1'), RmaRequest::class);

        $ids = [];

        foreach ($collection as $rma) {
            $ids[] = $rma->id;
        }

        self::assertSame([1234, 1235], $ids);
    }

    public function testFirstAndIsEmpty(): void
    {
        $collection = ResourceCollection::fromPayload(Fixtures::load('rma_requests_page_1'), RmaRequest::class);
        $first = $collection->first();

        self::assertInstanceOf(RmaRequest::class, $first);
        self::assertSame(1234, $first->id);
        self::assertFalse($collection->isEmpty());

        $empty = ResourceCollection::fromPayload(['member' => [], 'totalItems' => 0], RmaRequest::class);

        self::assertTrue($empty->isEmpty());
        self::assertNull($empty->first());
        self::assertCount(0, $empty);
        self::assertSame([], $empty->view());
    }

    /**
     * ResponseParser normalises a bare JSON array - what the server sends when
     * Accept: application/ld+json is missing - into the same envelope, so the collection
     * still works, just without pagination links.
     */
    public function testItAcceptsTheNormalisedBareArrayEnvelope(): void
    {
        $collection = ResourceCollection::fromPayload(
            ['member' => [['id' => 1, 'label' => 'Shop'], ['id' => 2]], 'totalItems' => 2],
            SaleChannel::class,
        );

        self::assertCount(2, $collection);
        self::assertSame('Shop', $collection->member()[0]->label);
        self::assertFalse($collection->hasNextPage());
    }

    public function testTotalItemsFallsBackToThePageSizeWhenTheServerOmitsIt(): void
    {
        $collection = ResourceCollection::fromPayload(['member' => [['id' => 1]]], SaleChannel::class);

        self::assertSame(1, $collection->totalItems());
    }

    public function testToArrayMapsEveryMember(): void
    {
        $collection = ResourceCollection::fromPayload(Fixtures::load('rma_requests_page_1'), RmaRequest::class);
        $array = $collection->toArray();

        self::assertCount(2, $array);
        self::assertSame(1234, $array[0]['id']);
        self::assertSame(1235, $array[1]['id']);
    }

    public function testAPayloadThatIsNotACollectionYieldsAnEmptyOne(): void
    {
        $collection = ResourceCollection::fromPayload(Fixtures::load('rma_request'), RmaRequest::class);

        self::assertTrue($collection->isEmpty());
        self::assertSame(0, $collection->totalItems());
    }
}
