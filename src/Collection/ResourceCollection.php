<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Collection;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use RetJetApi\Returns\Http\ResponseParser;
use RetJetApi\Returns\Model\Model;

/**
 * One page of a Hydra collection: the hydrated members plus the metadata that came with them.
 *
 * count() is the size of this page, not of the whole result set - use totalItems() for that,
 * which reads the count the server reports for the collection rather than for the page.
 * The two are different numbers whenever the collection spans more than one page, and
 * conflating them is the classic pagination bug.
 *
 * @template T of Model
 *
 * @implements IteratorAggregate<int, T>
 */
final readonly class ResourceCollection implements Countable, IteratorAggregate
{
    /**
     * @param list<T>               $member
     * @param array<string, string> $view Hydra `view` members: first, last, previous, next
     */
    public function __construct(
        private array $member,
        private int $totalItems,
        private array $view = [],
    ) {
    }

    /**
     * Hydrates a decoded collection payload.
     *
     * @template TModel of Model
     *
     * @param array<string, mixed>  $payload decoded response body
     * @param class-string<TModel>  $model   the model to hydrate each member into
     *
     * @return self<TModel>
     */
    public static function fromPayload(array $payload, string $model): self
    {
        $unwrapped = ResponseParser::unwrapCollection($payload);

        $member = [];

        foreach ($unwrapped['member'] as $item) {
            $member[] = $model::fromArray($item);
        }

        return new self($member, $unwrapped['totalItems'], $unwrapped['view']);
    }

    /**
     * The hydrated members of this page.
     *
     * @return list<T>
     */
    public function member(): array
    {
        return $this->member;
    }

    /**
     * How many items the whole collection holds, across every page.
     */
    public function totalItems(): int
    {
        return $this->totalItems;
    }

    /**
     * The `view` members, e.g. ['first' => '/v1/rma-requests?page=1', ...]. Populated from a Hydra
     * envelope when the server sends one, and from the `Link` header when it paginates that way.
     *
     * @return array<string, string>
     */
    public function view(): array
    {
        return $this->view;
    }

    /**
     * URL of the next page, or null on the last one. Absolute or relative exactly as the
     * API sent it; the transport accepts either.
     */
    public function nextPage(): ?string
    {
        return $this->view['next'] ?? null;
    }

    public function previousPage(): ?string
    {
        return $this->view['previous'] ?? null;
    }

    public function hasNextPage(): bool
    {
        return isset($this->view['next']);
    }

    /**
     * @return T|null
     */
    public function first(): ?Model
    {
        return $this->member[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->member === [];
    }

    /**
     * Number of items on this page.
     */
    public function count(): int
    {
        return count($this->member);
    }

    /**
     * @return ArrayIterator<int, T>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->member);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        $items = [];

        foreach ($this->member as $item) {
            $items[] = $item->toArray();
        }

        return $items;
    }
}
