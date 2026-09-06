<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Model;

/**
 * A file attached to an RMA request: customer-uploaded evidence in `attachments`, or a
 * document the returns process generated (e.g. a confirmation PDF) in `confirmations`.
 */
final readonly class RmaRequestFile implements Model
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public ?string $url = null,
        public ?string $mime = null,
        private array $raw = [],
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            Hydration::string($data, 'url'),
            Hydration::string($data, 'mime'),
            $data,
        );
    }

    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'mime' => $this->mime,
        ];
    }

    public function raw(): array
    {
        return $this->raw;
    }
}
