<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Model;

/**
 * One product line within an RMA request: what was requested, what was confirmed, and the
 * ordered product it refers to.
 *
 * `rmaRequestItemReason`, `rmaRequestIItemResolution` and `rmaRequestIItemCondition` are IRI
 * references (e.g. "/v1/rma-request-items-reasons/1"), not embedded objects; fetch the dictionary
 * entry through ItemReasons/ItemResolutions/ItemConditions when the details are needed. The
 * double "I" in the latter two is spelled that way in the spec itself - a generator artefact
 * kept verbatim because it is the wire key, not a typo the SDK introduced.
 */
final readonly class RmaRequestItem implements Model
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public ?int $id = null,
        public ?int $requestedQty = null,
        public ?float $requestedAmount = null,
        public ?string $requestedCurrency = null,
        public ?int $confirmedQty = null,
        public ?float $confirmedAmount = null,
        public ?string $confirmedCurrency = null,
        public ?string $label = null,
        public ?string $labelTranslated = null,
        public ?string $state = null,
        public ?string $rmaRequestItemReason = null,
        public ?string $rmaRequestIItemResolution = null,
        public ?string $rmaRequestIItemCondition = null,
        public ?OrderedProduct $orderedProduct = null,
        private array $raw = [],
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Hydration::int($data, 'id'),
            Hydration::int($data, 'requestedQty'),
            Hydration::float($data, 'requestedAmount'),
            Hydration::string($data, 'requestedCurrency'),
            Hydration::int($data, 'confirmedQty'),
            Hydration::float($data, 'confirmedAmount'),
            Hydration::string($data, 'confirmedCurrency'),
            Hydration::string($data, 'label'),
            Hydration::string($data, 'labelTranslated'),
            Hydration::string($data, 'state'),
            Hydration::string($data, 'rmaRequestItemReason'),
            Hydration::string($data, 'rmaRequestIItemResolution'),
            Hydration::string($data, 'rmaRequestIItemCondition'),
            Hydration::nested($data, 'orderedProduct', OrderedProduct::class),
            $data,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'requestedQty' => $this->requestedQty,
            'requestedAmount' => $this->requestedAmount,
            'requestedCurrency' => $this->requestedCurrency,
            'confirmedQty' => $this->confirmedQty,
            'confirmedAmount' => $this->confirmedAmount,
            'confirmedCurrency' => $this->confirmedCurrency,
            'label' => $this->label,
            'labelTranslated' => $this->labelTranslated,
            'state' => $this->state,
            'rmaRequestItemReason' => $this->rmaRequestItemReason,
            'rmaRequestIItemResolution' => $this->rmaRequestIItemResolution,
            'rmaRequestIItemCondition' => $this->rmaRequestIItemCondition,
            'orderedProduct' => $this->orderedProduct?->toArray(),
        ];
    }

    public function raw(): array
    {
        return $this->raw;
    }
}
