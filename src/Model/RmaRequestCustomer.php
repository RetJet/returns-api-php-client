<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Model;

/**
 * Person who submitted the RMA request, with their address.
 */
final readonly class RmaRequestCustomer implements Model
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public ?string $email = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $country = null,
        public ?string $state = null,
        public ?string $city = null,
        public ?string $zip = null,
        public ?string $address1 = null,
        public ?string $address2 = null,
        public ?string $phone = null,
        public ?string $company = null,
        public ?string $taxId = null,
        private array $raw = [],
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Hydration::string($data, 'email'),
            Hydration::string($data, 'firstName'),
            Hydration::string($data, 'lastName'),
            Hydration::string($data, 'country'),
            Hydration::string($data, 'state'),
            Hydration::string($data, 'city'),
            Hydration::string($data, 'zip'),
            Hydration::string($data, 'address1'),
            Hydration::string($data, 'address2'),
            Hydration::string($data, 'phone'),
            Hydration::string($data, 'company'),
            Hydration::string($data, 'taxId'),
            $data,
        );
    }

    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'country' => $this->country,
            'state' => $this->state,
            'city' => $this->city,
            'zip' => $this->zip,
            'address1' => $this->address1,
            'address2' => $this->address2,
            'phone' => $this->phone,
            'company' => $this->company,
            'taxId' => $this->taxId,
        ];
    }

    public function raw(): array
    {
        return $this->raw;
    }
}
