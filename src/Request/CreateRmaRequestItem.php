<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Request;

/**
 * One entry of the `items` member of CreateRmaRequest.
 *
 * `orderId` and `productId` identify the ordered product the way the sale channel does - the
 * same values OrderedProduct::$orderId/$productId report back on read. `lineId` disambiguates
 * them further where the sale channel distinguishes multiple lines of the same product.
 *
 * `orderId`, `productId`, `quantity`, `reasonId` and `conditionId` are required by the schema;
 * `resolutionId` and everything else are optional.
 */
final readonly class CreateRmaRequestItem implements Payload
{
    use SerializesSetMembers;

    /**
     * @param array<string, mixed> $extra fills gaps left by the named members above that are
     *                                    still null; a named member that is set wins over the
     *                                    same key here
     */
    public function __construct(
        public string $orderId,
        public string $productId,
        public int $quantity,
        public int $reasonId,
        public int $conditionId,
        public ?string $lineId = null,
        public ?string $name = null,
        public ?float $price = null,
        public ?string $currency = null,
        public ?int $resolutionId = null,
        public array $extra = [],
    ) {
    }

    public function toArray(): array
    {
        return self::withExtra([
            'orderId' => $this->orderId,
            'productId' => $this->productId,
            'quantity' => $this->quantity,
            'reasonId' => $this->reasonId,
            'conditionId' => $this->conditionId,
            'lineId' => $this->lineId,
            'name' => $this->name,
            'price' => $this->price,
            'currency' => $this->currency,
            'resolutionId' => $this->resolutionId,
        ], $this->extra);
    }
}
