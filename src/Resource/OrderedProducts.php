<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Resource;

use Generator;
use RetJetApi\Returns\Collection\ResourceCollection;
use RetJetApi\Returns\Model\OrderedProduct;

/**
 * Products from the original orders.

 * Read-only and collection-only: the API publishes no `/v1/ordered-product/{id}` operation,
 * so this resource deliberately has no get().
 *
 * @extends AbstractResource<OrderedProduct>
 */
final class OrderedProducts extends AbstractResource
{
    /**
     * One page: GET /v1/ordered-products
     *
     * @param array<string, scalar|null> $query extra query parameters, merged with `page`
     *
     * @return ResourceCollection<OrderedProduct>
     */
    public function list(int $page = 1, array $query = []): ResourceCollection
    {
        return $this->collection($page, $query);
    }

    /**
     * Every entry across every page, fetched one page at a time as the iteration advances.
     *
     * @param array<string, scalar|null> $query
     *
     * @return Generator<int, OrderedProduct>
     */
    public function iterate(array $query = []): Generator
    {
        return $this->paginate($query)->getIterator();
    }

    protected function model(): string
    {
        return OrderedProduct::class;
    }

    protected function collectionPath(): string
    {
        return '/v1/ordered-products';
    }

    protected function itemPath(): ?string
    {
        return null;
    }
}
