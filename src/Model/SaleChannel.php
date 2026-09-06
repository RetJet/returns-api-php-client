<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Model;

/**
 * A shop or marketplace integration the returns come from.
 *
 * `returnPointId` is always present; `returnPointAddress` - the embedded ReturnPoint - is only
 * sent when the channel is read on its own, so a list response typically carries the id alone.
 */
final readonly class SaleChannel implements Model
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public ?int $id = null,
        public ?string $label = null,
        public ?string $name = null,
        public ?string $channelType = null,
        public ?int $maxReturnDaysProcessingPolicy = null,
        public ?int $maxWarrantyDaysProcessingPolicy = null,
        public ?ReturnPoint $returnPointAddress = null,
        public ?int $returnPointId = null,
        private array $raw = [],
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Hydration::int($data, 'id'),
            Hydration::string($data, 'label'),
            Hydration::string($data, 'name'),
            Hydration::string($data, 'channelType'),
            Hydration::int($data, 'maxReturnDaysProcessingPolicy'),
            Hydration::int($data, 'maxWarrantyDaysProcessingPolicy'),
            Hydration::nested($data, 'returnPointAddress', ReturnPoint::class),
            Hydration::int($data, 'returnPointId'),
            $data,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'name' => $this->name,
            'channelType' => $this->channelType,
            'maxReturnDaysProcessingPolicy' => $this->maxReturnDaysProcessingPolicy,
            'maxWarrantyDaysProcessingPolicy' => $this->maxWarrantyDaysProcessingPolicy,
            'returnPointAddress' => $this->returnPointAddress?->toArray(),
            'returnPointId' => $this->returnPointId,
        ];
    }

    public function raw(): array
    {
        return $this->raw;
    }
}
