<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Resource;

use Generator;
use RetJetApi\Returns\Collection\ResourceCollection;
use RetJetApi\Returns\Model\ItemCondition;

/**
 * Dictionary of the conditions a returned item can arrive in.
 *
 * @extends AbstractResource<ItemCondition>
 */
final class ItemConditions extends AbstractResource
{
    /**
     * One page: GET /v1/rma-request-items-conditions
     *
     * @param array<string, scalar|null> $query extra query parameters, merged with `page`
     *
     * @return ResourceCollection<ItemCondition>
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
     * @return Generator<int, ItemCondition>
     */
    public function iterate(array $query = []): Generator
    {
        return $this->paginate($query)->getIterator();
    }

    /**
     * One entry by id: GET /v1/rma-request-items-conditions/{id}
     */
    public function get(int $id): ItemCondition
    {
        return $this->item($id);
    }

    protected function model(): string
    {
        return ItemCondition::class;
    }

    protected function collectionPath(): string
    {
        return '/v1/rma-request-items-conditions';
    }

    protected function itemPath(): string
    {
        return '/v1/rma-request-items-conditions/{id}';
    }
}
