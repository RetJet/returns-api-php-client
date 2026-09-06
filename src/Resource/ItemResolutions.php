<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Resource;

use Generator;
use RetJetApi\Returns\Collection\ResourceCollection;
use RetJetApi\Returns\Model\ItemResolution;

/**
 * Dictionary of the ways a return can be settled.
 *
 * @extends AbstractResource<ItemResolution>
 */
final class ItemResolutions extends AbstractResource
{
    /**
     * One page: GET /v1/rma-request-items-resolutions
     *
     * @param array<string, scalar|null> $query extra query parameters, merged with `page`
     *
     * @return ResourceCollection<ItemResolution>
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
     * @return Generator<int, ItemResolution>
     */
    public function iterate(array $query = []): Generator
    {
        return $this->paginate($query)->getIterator();
    }

    /**
     * One entry by id: GET /v1/rma-request-items-resolutions/{id}
     */
    public function get(int $id): ItemResolution
    {
        return $this->item($id);
    }

    protected function model(): string
    {
        return ItemResolution::class;
    }

    protected function collectionPath(): string
    {
        return '/v1/rma-request-items-resolutions';
    }

    protected function itemPath(): string
    {
        return '/v1/rma-request-items-resolutions/{id}';
    }
}
