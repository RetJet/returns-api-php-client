<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Collection;

use Generator;
use IteratorAggregate;
use RetJetApi\Returns\Http\Transport;
use RetJetApi\Returns\Model\Model;

/**
 * Walks a collection page by page, following the server's own next link until it runs out.
 *
 * Nothing is fetched when the paginator is constructed, and each page is fetched only once
 * the consumer has worked through the previous one - the generator suspends at every yield,
 * so `foreach ($paginator as $rma) { ... break; }` costs exactly one request. That makes it
 * safe to iterate result sets far larger than memory, which is what it is for; use
 * ResourceCollection when a single page is what you actually want.
 *
 * Following the server's own next link rather than incrementing a page counter keeps the SDK
 * correct if the API ever changes how it paginates. Where that link arrives is not this class's
 * business: the API sends it as `Link: <...>; rel="next"` and ResponseParser folds it into the
 * same `view.next` an API emitting a Hydra envelope would have put in the body.
 *
 * @template T of Model
 *
 * @implements IteratorAggregate<int, T>
 */
final class Paginator implements IteratorAggregate
{
    /**
     * Backstop for a proxy that fabricates a fresh `next` URL on every response: unlike a
     * repeated URL, that case is never caught by the visited-URL check below, so the walk
     * needs a point at which it gives up regardless. No real collection approaches this.
     */
    private const MAX_PAGES = 10_000;

    /**
     * @param class-string<T>            $model the model each member is hydrated into
     * @param array<string, scalar|null> $query query for the first request only; later pages
     *                                          reuse the link the server sent, which already
     *                                          carries every parameter
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly string $path,
        private readonly string $model,
        private readonly array $query = [],
    ) {
    }

    /**
     * Every item of every page, in order.
     *
     * @return Generator<int, T>
     */
    public function getIterator(): Generator
    {
        foreach ($this->pages() as $page) {
            foreach ($page as $item) {
                yield $item;
            }
        }
    }

    /**
     * The pages themselves, for consumers that need totalItems() or want to process a page
     * at a time.
     *
     * @return Generator<int, ResourceCollection<T>>
     */
    public function pages(): Generator
    {
        $payload = $this->transport->request('GET', $this->path, $this->query);
        $visited = [$this->path => true];
        $pages = 1;

        while (true) {
            $page = ResourceCollection::fromPayload($payload, $this->model);

            yield $page;

            $next = $page->nextPage();

            // A next link revisiting any page already fetched - not only the one just
            // fetched - would loop forever; API Platform does not emit one, but a proxy
            // rewriting links might produce a longer cycle than A -> B -> A.
            if ($next === null || isset($visited[$next])) {
                return;
            }

            if (++$pages > self::MAX_PAGES) {
                return;
            }

            $visited[$next] = true;
            $payload = $this->transport->request('GET', $next);
        }
    }
}
