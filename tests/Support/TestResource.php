<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Support;

use RetJetApi\Returns\Collection\Paginator;
use RetJetApi\Returns\Collection\ResourceCollection;
use RetJetApi\Returns\Http\Transport;
use RetJetApi\Returns\Model\SaleChannel;
use RetJetApi\Returns\Resource\AbstractResource;

/**
 * Exposes AbstractResource's protected building blocks so they can be tested directly,
 * instead of only through whichever shipped resource happens to use them.
 *
 * @extends AbstractResource<SaleChannel>
 */
final class TestResource extends AbstractResource
{
    public function __construct(Transport $transport, private readonly ?string $itemPath = null)
    {
        parent::__construct($transport);
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, string>      $headers
     *
     * @return array<string, mixed>
     */
    public function callFetch(string $path, array $query = [], array $headers = []): array
    {
        return $this->fetch($path, $query, $headers);
    }

    /**
     * @param array<array-key, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    public function callPost(string $path, ?array $body = null): array
    {
        return $this->post($path, $body);
    }

    /**
     * @param array<array-key, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    public function callPut(string $path, ?array $body = null): array
    {
        return $this->put($path, $body);
    }

    /**
     * @param array<array-key, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    public function callDelete(string $path, ?array $body = null): array
    {
        return $this->delete($path, $body);
    }

    public function callItem(int|string $id): SaleChannel
    {
        return $this->item($id);
    }

    /**
     * @return ResourceCollection<SaleChannel>
     */
    /**
     * @param array<string, scalar|null> $query
     *
     * @return ResourceCollection<SaleChannel>
     */
    public function callCollection(int $page = 1, array $query = []): ResourceCollection
    {
        return $this->collection($page, $query);
    }

    /**
     * @param array<string, scalar|null> $query
     *
     * @return Paginator<SaleChannel>
     */
    public function callPaginate(array $query = []): Paginator
    {
        return $this->paginate($query);
    }

    protected function model(): string
    {
        return SaleChannel::class;
    }

    protected function collectionPath(): string
    {
        return '/v1/sale-channels';
    }

    protected function itemPath(): ?string
    {
        return $this->itemPath;
    }
}
