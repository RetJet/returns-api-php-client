<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Request;

/**
 * Body for POST /v1/rma-requests.
 *
 * The API now describes this as a dedicated schema (`RmaRequest.CreateRmaRequest`) rather than
 * reusing the read-side RmaRequest shape, and it is considerably narrower: `saleChannelId` is
 * a plain id, not the IRI the read side reports, and there is no member for `identifier`,
 * `uuid`, `totalRequestedAmount`, `totalRequestedCurrency` or `customerLocale` - those are
 * server-assigned or server-derived and cannot be set on create.
 *
 * `saleChannelId` and `customer` are required by the schema. `items` carries no `required` of
 * its own, but the schema's own description calls it "at least one is required", so it is
 * required here too rather than trusting an omission that would only produce a 400 from the
 * server anyway.
 */
final readonly class CreateRmaRequest implements Payload
{
    use SerializesSetMembers;

    /**
     * @param list<CreateRmaRequestItem> $items at least one is required
     * @param array<string, mixed>       $extra fills gaps left by the named members above that
     *                                          are still null; a named member that is set wins
     *                                          over the same key here
     */
    public function __construct(
        public int $saleChannelId,
        public CreateRmaRequestCustomer $customer,
        public array $items,
        public ?string $type = null,
        public ?string $customerInfo = null,
        public ?string $customerInstruction = null,
        public ?string $refundBankAccountNo = null,
        public array $extra = [],
    ) {
    }

    public function toArray(): array
    {
        return self::withExtra([
            'saleChannelId' => $this->saleChannelId,
            'customer' => $this->customer->toArray(),
            'items' => array_map(
                static fn (CreateRmaRequestItem $item): array => $item->toArray(),
                $this->items,
            ),
            'type' => $this->type,
            'customerInfo' => $this->customerInfo,
            'customerInstruction' => $this->customerInstruction,
            'refundBankAccountNo' => $this->refundBankAccountNo,
        ], $this->extra);
    }
}
