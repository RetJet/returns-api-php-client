<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Model;

/**
 * Workflow state of an RMA request (new, in_progress, approved, rejected, completed, ...).
 *
 * `label` is the machine identifier, `labelTranslated` the display form in the account's
 * locale, and `state` the underlying workflow state the label belongs to.
 */
final readonly class RmaRequestState implements Model
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public ?string $label = null,
        public ?string $labelTranslated = null,
        public ?string $state = null,
        public ?string $labelColor = null,
        private array $raw = [],
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Hydration::string($data, 'label'),
            Hydration::string($data, 'labelTranslated'),
            Hydration::string($data, 'state'),
            Hydration::string($data, 'labelColor'),
            $data,
        );
    }

    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'labelTranslated' => $this->labelTranslated,
            'state' => $this->state,
            'labelColor' => $this->labelColor,
        ];
    }

    public function raw(): array
    {
        return $this->raw;
    }
}
