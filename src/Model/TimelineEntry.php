<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Model;

use DateTimeImmutable;

/**
 * One event in an RMA request's history (`RmaRequestTimeline` in the spec).
 *
 * `public` tells whether the entry is visible to the customer or internal only.
 * `user` and `data` stay in raw(): the spec declares both as arrays of string, which does
 * not match what an actor reference and an event payload can plausibly be, so they are not
 * typed until a live response confirms their shape.
 *
 * `createdAt` is Unix seconds, as sent by the API; createdAtAsDateTime() converts it to a
 * `DateTimeImmutable` for callers who want one.
 */
final readonly class TimelineEntry implements Model
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public ?int $id = null,
        public ?string $type = null,
        public ?int $createdAt = null,
        public ?string $description = null,
        public ?bool $public = null,
        private array $raw = [],
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Hydration::int($data, 'id'),
            Hydration::string($data, 'type'),
            Hydration::int($data, 'createdAt'),
            Hydration::string($data, 'description'),
            Hydration::bool($data, 'public'),
            $data,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'createdAt' => $this->createdAt,
            'description' => $this->description,
            'public' => $this->public,
        ];
    }

    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * `createdAt` as a UTC `DateTimeImmutable`, or null when the API sent none.
     */
    public function createdAtAsDateTime(): ?DateTimeImmutable
    {
        return Hydration::unixTimestamp($this->createdAt);
    }
}
