<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Resource;

use Generator;
use RetJetApi\Returns\Collection\ResourceCollection;
use RetJetApi\Returns\Model\ReturnPoint;

/**
 * Addresses returned goods are sent back to.
 *
 * @extends AbstractResource<ReturnPoint>
 */
final class ReturnPoints extends AbstractResource
{
    /**
     * One page: GET /v1/return-points
     *
     * @param array<string, scalar|null> $query extra query parameters, merged with `page`
     *
     * @return ResourceCollection<ReturnPoint>
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
     * @return Generator<int, ReturnPoint>
     */
    public function iterate(array $query = []): Generator
    {
        return $this->paginate($query)->getIterator();
    }

    /**
     * One entry by id: GET /v1/return-points/{id}
     */
    public function get(int $id): ReturnPoint
    {
        return $this->item($id);
    }

    protected function model(): string
    {
        return ReturnPoint::class;
    }

    protected function collectionPath(): string
    {
        return '/v1/return-points';
    }

    protected function itemPath(): string
    {
        return '/v1/return-points/{id}';
    }
}
