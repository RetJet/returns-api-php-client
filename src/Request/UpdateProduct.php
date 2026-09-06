<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Request;

/**
 * Body for PUT /v1/rma-requests/{requestId}/product/{productId} - the confirmed quantity and
 * amount an agent settles a single returned product at.
 *
 * `requestId` and `productId` are not part of this object: they identify the target and are
 * arguments of RmaRequests::updateProduct(), which puts them in the path.
 */
final readonly class UpdateProduct implements Payload
{
    use SerializesSetMembers;

    /**
     * @param array<string, mixed> $extra fills gaps left by the named members above that are
     *                                    still null; a named member that is set wins over the
     *                                    same key here
     */
    public function __construct(
        public ?int $confirmedQty = null,
        public ?float $confirmedAmount = null,
        public ?string $confirmedCurrency = null,
        public array $extra = [],
    ) {
    }

    public function toArray(): array
    {
        return self::withExtra([
            'confirmedQty' => $this->confirmedQty,
            'confirmedAmount' => $this->confirmedAmount,
            'confirmedCurrency' => $this->confirmedCurrency,
        ], $this->extra);
    }
}
