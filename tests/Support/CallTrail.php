<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Support;

/**
 * Shared, ordered list of labels, used to observe the order a middleware stack runs in.
 */
final class CallTrail
{
    /** @var list<string> */
    private array $entries = [];

    public function add(string $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * @return list<string>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
