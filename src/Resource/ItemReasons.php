<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Resource;

use Generator;
use RetJetApi\Returns\Collection\ResourceCollection;
use RetJetApi\Returns\Model\ItemReason;

/**
 * Dictionary of the reasons an item can be returned for.
 *
 * @extends AbstractResource<ItemReason>
 */
final class ItemReasons extends AbstractResource
{
    /**
     * One page: GET /v1/rma-request-items-reasons
     *
     * @param array<string, scalar|null> $query extra query parameters, merged with `page`
     *
     * @return ResourceCollection<ItemReason>
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
     * @return Generator<int, ItemReason>
     */
    public function iterate(array $query = []): Generator
    {
        return $this->paginate($query)->getIterator();
    }

    /**
     * One entry by id: GET /v1/rma-request-items-reasons/{id}
     */
    public function get(int $id): ItemReason
    {
        return $this->item($id);
    }

    protected function model(): string
    {
        return ItemReason::class;
    }

    protected function collectionPath(): string
    {
        return '/v1/rma-request-items-reasons';
    }

    protected function itemPath(): string
    {
        return '/v1/rma-request-items-reasons/{id}';
    }
}
