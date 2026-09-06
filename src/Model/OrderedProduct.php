<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Model;

/**
 * A product line from the original order, as the API knows it.
 *
 * Read-only in this API: there is a collection endpoint but no item endpoint.
 *
 * `orderId`, `productId` and `lineId` are the sale channel's own identifiers - pass them back
 * as `items[].orderId`/`items[].productId`/`items[].lineId` when creating a return.
 */
final readonly class OrderedProduct implements Model
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public ?int $id = null,
        public ?string $name = null,
        public ?string $cover = null,
        public ?string $sku = null,
        public ?float $price = null,
        public ?string $currency = null,
        public ?int $quantity = null,
        public ?string $remoteOrder = null,
        public ?string $orderId = null,
        public ?string $productId = null,
        public ?string $lineId = null,
        private array $raw = [],
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Hydration::int($data, 'id'),
            Hydration::string($data, 'name'),
            Hydration::string($data, 'cover'),
            Hydration::string($data, 'sku'),
            Hydration::float($data, 'price'),
            Hydration::string($data, 'currency'),
            Hydration::int($data, 'quantity'),
            Hydration::string($data, 'remoteOrder'),
            Hydration::string($data, 'orderId'),
            Hydration::string($data, 'productId'),
            Hydration::string($data, 'lineId'),
            $data,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'cover' => $this->cover,
            'sku' => $this->sku,
            'price' => $this->price,
            'currency' => $this->currency,
            'quantity' => $this->quantity,
            'remoteOrder' => $this->remoteOrder,
            'orderId' => $this->orderId,
            'productId' => $this->productId,
            'lineId' => $this->lineId,
        ];
    }

    public function raw(): array
    {
        return $this->raw;
    }
}
