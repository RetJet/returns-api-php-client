<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Model;

/**
 * A physical address returned goods are sent back to.
 */
final readonly class ReturnPoint implements Model
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public ?int $id = null,
        public ?string $customLabel = null,
        public ?string $name = null,
        public ?string $country = null,
        public ?string $state = null,
        public ?string $city = null,
        public ?string $zip = null,
        public ?string $address1 = null,
        public ?string $address2 = null,
        public ?string $contactPhone = null,
        public ?string $contactEmail = null,
        private array $raw = [],
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Hydration::int($data, 'id'),
            Hydration::string($data, 'customLabel'),
            Hydration::string($data, 'name'),
            Hydration::string($data, 'country'),
            Hydration::string($data, 'state'),
            Hydration::string($data, 'city'),
            Hydration::string($data, 'zip'),
            Hydration::string($data, 'address1'),
            Hydration::string($data, 'address2'),
            Hydration::string($data, 'contactPhone'),
            Hydration::string($data, 'contactEmail'),
            $data,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'customLabel' => $this->customLabel,
            'name' => $this->name,
            'country' => $this->country,
            'state' => $this->state,
            'city' => $this->city,
            'zip' => $this->zip,
            'address1' => $this->address1,
            'address2' => $this->address2,
            'contactPhone' => $this->contactPhone,
            'contactEmail' => $this->contactEmail,
        ];
    }

    public function raw(): array
    {
        return $this->raw;
    }
}
