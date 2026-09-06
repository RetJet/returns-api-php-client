<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Resource;

use RetJetApi\Returns\Collection\Paginator;
use RetJetApi\Returns\Collection\ResourceCollection;
use RetJetApi\Returns\Exception\ConfigurationException;
use RetJetApi\Returns\Http\Transport;
use RetJetApi\Returns\Model\Model;

/**
 * Base for every resource: the four HTTP verbs over the transport, plus the three helpers
 * that turn a response into models.
 *
 * A subclass declares two facts - which model it hydrates and where its collection lives -
 * and then writes one thin method per API operation. Everything a method needs is a path and
 * a payload, so an operation is a single expression and the path it targets is visible right
 * next to it. The API is regular today - the collection, the item and every action all sit
 * under the same plural segment - but it has not always been (it used a singular segment for
 * items and actions until 2026-09), so the path a method targets stays written out next to it
 * rather than derived from a convention that a later rename could silently invalidate.
 *
 * The GET verb is called fetch(), not get(), for one reason: `get($id)` is the public API of
 * almost every resource, and PHP will not let a subclass narrow `get(string $path, array
 * $query)` down to `get(int $id)`.
 *
 * @template T of Model
 */
abstract class AbstractResource
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * The model this resource hydrates.
     *
     * @return class-string<T>
     */
    abstract protected function model(): string;

    /**
     * Collection path, copied verbatim from `paths` in the OpenAPI document.
     */
    abstract protected function collectionPath(): string;

    /**
     * Item path template with an `{id}` placeholder, or null when the API exposes no item
     * endpoint - which is the case for OrderedProduct.
     */
    protected function itemPath(): ?string
    {
        return null;
    }

    /**
     * The transport, for operations that need something the helpers below do not cover.
     */
    protected function transport(): Transport
    {
        return $this->transport;
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, string>      $headers
     *
     * @return array<string, mixed>
     */
    protected function fetch(string $path, array $query = [], array $headers = []): array
    {
        return $this->transport->request('GET', $path, $query, null, $headers);
    }

    /**
     * @param array<array-key, mixed>|null $body
     * @param array<string, scalar|null>   $query
     *
     * @return array<string, mixed>
     */
    protected function post(string $path, ?array $body = null, array $query = []): array
    {
        return $this->transport->request('POST', $path, $query, $body ?? []);
    }

    /**
     * @param array<array-key, mixed>|null $body
     * @param array<string, scalar|null>   $query
     *
     * @return array<string, mixed>
     */
    protected function put(string $path, ?array $body = null, array $query = []): array
    {
        return $this->transport->request('PUT', $path, $query, $body ?? []);
    }

    /**
     * @param array<array-key, mixed>|null $body
     * @param array<string, scalar|null>   $query
     *
     * @return array<string, mixed>
     */
    protected function delete(string $path, ?array $body = null, array $query = []): array
    {
        return $this->transport->request('DELETE', $path, $query, $body);
    }

    /**
     * One item, hydrated. The id is URL-encoded, so it is safe for the string identifiers
     * some resources use.
     *
     * @return T
     *
     * @throws ConfigurationException when the resource declares no item path
     */
    protected function item(int|string $id): Model
    {
        $template = $this->itemPath();

        if ($template === null) {
            throw ConfigurationException::noItemEndpoint(static::class);
        }

        return $this->hydrate($this->fetch(str_replace('{id}', rawurlencode((string) $id), $template)));
    }

    /**
     * One page of the collection. `page` is the only parameter the API accepts, and it is
     * always sent, so list() and list(page: 1) produce the identical request.
     *
     * The $page argument is authoritative: assigning it last means a stray `page` inside
     * $query is overwritten rather than silently fighting the named argument.
     *
     * @param array<string, scalar|null> $query
     *
     * @return ResourceCollection<T>
     */
    protected function collection(int $page = 1, array $query = []): ResourceCollection
    {
        $query['page'] = $page;

        return ResourceCollection::fromPayload(
            $this->fetch($this->collectionPath(), $query),
            $this->model(),
        );
    }

    /**
     * Every page, lazily. No request is sent until the result is iterated.
     *
     * Unlike collection(), this takes no $page argument: a `page` inside $query is honoured
     * and sets the page the walk starts from, after which it follows Hydra's view.next.
     *
     * @param array<string, scalar|null> $query
     *
     * @return Paginator<T>
     */
    protected function paginate(array $query = []): Paginator
    {
        return new Paginator($this->transport, $this->collectionPath(), $this->model(), $query);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return T
     */
    protected function hydrate(array $payload): Model
    {
        $model = $this->model();

        return $model::fromArray($payload);
    }
}
