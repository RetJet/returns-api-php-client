<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Model;

/**
 * Dictionary entry describing the condition a returned item arrived in (new_unopened, used, damaged, ...).
 *
 * `label` is the stable machine identifier to compare against; `labelTranslated` is the
 * display form in the account's locale and must not be used as a key.
 */
final readonly class ItemCondition implements Model
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public ?int $id = null,
        public ?string $label = null,
        public ?string $labelTranslated = null,
        public ?bool $isActive = null,
        public ?int $position = null,
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
            position: Hydration::int($data, 'position'),
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
            'position' => $this->position,
        ];
    }

    public function raw(): array
    {
        return $this->raw;
    }
}
