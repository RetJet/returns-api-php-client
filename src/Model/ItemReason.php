<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Model;

/**
 * Dictionary entry describing why an item is being returned (defective, wrong_size, changed_mind, ...).
 *
 * `label` is the stable machine identifier to compare against; `labelTranslated` is the
 * display form in the account's locale and must not be used as a key.
 */
final readonly class ItemReason implements Model
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public ?int $id = null,
        public ?string $label = null,
        public ?string $labelTranslated = null,
        public ?bool $isActive = null,
        public ?bool $isReturn = null,
        public ?bool $isWarranty = null,
        public ?int $position = null,
        public ?int $returnPosition = null,
        private array $raw = [],
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            id: Hydration::int($data, 'id'),
            label: Hydration::string($data, 'label'),
            labelTranslated: Hydration::string($data, 'labelTranslated'),
            isActive: Hydration::bool($data, 'isActive'),
            isReturn: Hydration::bool($data, 'isReturn'),
            isWarranty: Hydration::bool($data, 'isWarranty'),
            position: Hydration::int($data, 'position'),
            returnPosition: Hydration::int($data, 'returnPosition'),
            raw: $data,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'labelTranslated' => $this->labelTranslated,
            'isActive' => $this->isActive,
            'isReturn' => $this->isReturn,
            'isWarranty' => $this->isWarranty,
            'position' => $this->position,
            'returnPosition' => $this->returnPosition,
        ];
    }

    public function raw(): array
    {
        return $this->raw;
    }
}
