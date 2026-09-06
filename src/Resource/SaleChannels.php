<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Resource;

use Generator;
use RetJetApi\Returns\Collection\ResourceCollection;
use RetJetApi\Returns\Model\SaleChannel;

/**
 * Shops and marketplaces the returns come from.
 *
 * @extends AbstractResource<SaleChannel>
 */
final class SaleChannels extends AbstractResource
{
    /**
     * One page: GET /v1/sale-channels
     *
     * @param array<string, scalar|null> $query extra query parameters, merged with `page`
     *
     * @return ResourceCollection<SaleChannel>
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
     * @return Generator<int, SaleChannel>
     */
    public function iterate(array $query = []): Generator
    {
        return $this->paginate($query)->getIterator();
    }

    /**
     * One entry by id: GET /v1/sale-channels/{id}
     */
    public function get(int $id): SaleChannel
    {
        return $this->item($id);
    }

    protected function model(): string
    {
        return SaleChannel::class;
    }

    protected function collectionPath(): string
    {
        return '/v1/sale-channels';
    }

    protected function itemPath(): string
    {
        return '/v1/sale-channels/{id}';
    }
}
