<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Model;

/**
 * Someone kept in the loop on an RMA request.
 *
 * Every member describes the *user*, never the RMA request: the spec documents `id` as
 * "User ID of the follower" and `userId` as "User ID to follow/unfollow". This is the only
 * action schema with no member for the request itself, which is carried by the path alone.
 *
 * `id` and `userId` therefore look redundant, and which one a live response fills in is
 * unconfirmed.
 */
final readonly class RmaRequestFollower implements Model
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public ?int $id = null,
        public ?string $email = null,
        public ?string $name = null,
        public ?int $userId = null,
        private array $raw = [],
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Hydration::int($data, 'id'),
            Hydration::string($data, 'email'),
            Hydration::string($data, 'name'),
            Hydration::int($data, 'userId'),
            $data,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'userId' => $this->userId,
        ];
    }

    public function raw(): array
    {
        return $this->raw;
    }
}
