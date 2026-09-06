<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Model;

use DateTimeImmutable;

/**
 * A return / RMA request - the central resource of the API.
 *
 * `items` is the product lines the request covers; `confirmations` and `attachments` are
 * files - documents the returns process generated and evidence uploaded by the customer or an
 * agent, respectively. All three are typed lists, but stay nullable like every other member
 * here: the API can omit the member entirely, which is a different statement than "there are
 * none of them".
 *
 * `saleChannel` is an embedded object, not the IRI reference earlier versions of this API sent.
 *
 * Timestamps (`createdAt`, `deadlineTs`) are typed `?int` - Unix seconds, as sent by the API -
 * with `createdAtAsDateTime()`/`deadlineTsAsDateTime()` alongside them for callers who want a
 * `DateTimeImmutable` instead of doing that conversion themselves.
 */
final readonly class RmaRequest implements Model
{
    /**
     * @param array<int, RmaRequestItem>|null $items
     * @param array<int, RmaRequestFile>|null $confirmations
     * @param array<int, RmaRequestFile>|null $attachments
     * @param array<string, mixed>            $raw
     */
    public function __construct(
        public ?int $id = null,
        public ?string $uuid = null,
        public ?string $identifier = null,
        public ?string $type = null,
        public ?int $createdAt = null,
        public ?int $deadlineTs = null,
        public ?RmaRequestCustomer $customer = null,
        public ?string $customerLocale = null,
        public ?string $customerInfo = null,
        public ?string $refundBankAccountNo = null,
        public ?float $totalRequestedAmount = null,
        public ?string $totalRequestedCurrency = null,
        public ?float $totalConfirmedAmount = null,
        public ?string $totalConfirmedCurrency = null,
        public ?RmaRequestState $state = null,
        public ?SaleChannel $saleChannel = null,
        public ?array $items = null,
        public ?array $confirmations = null,
        public ?array $attachments = null,
        private array $raw = [],
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Hydration::int($data, 'id'),
            Hydration::string($data, 'uuid'),
            Hydration::string($data, 'identifier'),
            Hydration::string($data, 'type'),
            Hydration::int($data, 'createdAt'),
            Hydration::int($data, 'deadlineTs'),
            Hydration::nested($data, 'customer', RmaRequestCustomer::class),
            Hydration::string($data, 'customerLocale'),
            Hydration::string($data, 'customerInfo'),
            Hydration::string($data, 'refundBankAccountNo'),
            Hydration::float($data, 'totalRequestedAmount'),
            Hydration::string($data, 'totalRequestedCurrency'),
            Hydration::float($data, 'totalConfirmedAmount'),
            Hydration::string($data, 'totalConfirmedCurrency'),
            Hydration::nested($data, 'state', RmaRequestState::class),
            Hydration::nested($data, 'saleChannel', SaleChannel::class),
            Hydration::nestedList($data, 'items', RmaRequestItem::class),
            Hydration::nestedList($data, 'confirmations', RmaRequestFile::class),
            Hydration::nestedList($data, 'attachments', RmaRequestFile::class),
            $data,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'identifier' => $this->identifier,
            'type' => $this->type,
            'createdAt' => $this->createdAt,
            'deadlineTs' => $this->deadlineTs,
            'customer' => $this->customer?->toArray(),
            'customerLocale' => $this->customerLocale,
            'customerInfo' => $this->customerInfo,
            'refundBankAccountNo' => $this->refundBankAccountNo,
            'totalRequestedAmount' => $this->totalRequestedAmount,
            'totalRequestedCurrency' => $this->totalRequestedCurrency,
            'totalConfirmedAmount' => $this->totalConfirmedAmount,
            'totalConfirmedCurrency' => $this->totalConfirmedCurrency,
            'state' => $this->state?->toArray(),
            'saleChannel' => $this->saleChannel?->toArray(),
            'items' => self::toArrayList($this->items),
            'confirmations' => self::toArrayList($this->confirmations),
            'attachments' => self::toArrayList($this->attachments),
        ];
    }

    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * Maps a nested member's typed list back to arrays, keeping toArray()'s null-vs-empty
     * distinction: the three list members go through this the same way, so the null guard and
     * the mapping live once rather than three times.
     *
     * @param array<int, Model>|null $models
     *
     * @return array<int, array<string, mixed>>|null
     */
    private static function toArrayList(?array $models): ?array
    {
        return $models === null ? null : array_map(static fn (Model $model): array => $model->toArray(), $models);
    }

    /**
     * `createdAt` as a UTC `DateTimeImmutable`, or null when the API sent none.
     */
    public function createdAtAsDateTime(): ?DateTimeImmutable
    {
        return Hydration::unixTimestamp($this->createdAt);
    }

    /**
     * `deadlineTs` as a UTC `DateTimeImmutable`, or null when the API sent none.
     */
    public function deadlineTsAsDateTime(): ?DateTimeImmutable
    {
        return Hydration::unixTimestamp($this->deadlineTs);
    }
}
