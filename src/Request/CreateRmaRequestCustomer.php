<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Request;

/**
 * The `customer` member of CreateRmaRequest.
 *
 * `email`, `firstName`, `lastName`, `country`, `city` and `address1` are required by the
 * schema; everything else is optional. Unlike the read-side RmaRequestCustomer, the wire keys
 * here are already camelCase - there is no snake_case to translate.
 */
final readonly class CreateRmaRequestCustomer implements Payload
{
    use SerializesSetMembers;

    /**
     * @param array<string, mixed> $extra fills gaps left by the named members above that are
     *                                    still null; a named member that is set wins over the
     *                                    same key here
     */
    public function __construct(
        public string $email,
        public string $firstName,
        public string $lastName,
        public string $country,
        public string $city,
        public string $address1,
        public ?string $address2 = null,
        public ?string $zip = null,
        public ?string $state = null,
        public ?string $phone = null,
        public ?string $company = null,
        public ?string $taxId = null,
        public array $extra = [],
    ) {
    }

    public function toArray(): array
    {
        return self::withExtra([
            'email' => $this->email,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'country' => $this->country,
            'city' => $this->city,
            'address1' => $this->address1,
            'address2' => $this->address2,
            'zip' => $this->zip,
            'state' => $this->state,
            'phone' => $this->phone,
            'company' => $this->company,
            'taxId' => $this->taxId,
        ], $this->extra);
    }
}
