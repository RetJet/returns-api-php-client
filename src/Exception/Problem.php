<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Exception;

/**
 * RFC 7807 problem document (`application/problem+json`).
 *
 * Only the five members defined by the RFC are read. Anything else the server adds is
 * ignored by construction - most importantly `trace[]`, which the dev environment appends
 * to every error response and which must never leak into the SDK surface.
 */
final readonly class Problem
{
    public function __construct(
        private ?string $type = null,
        private ?string $title = null,
        private ?int $status = null,
        private ?string $detail = null,
        private ?string $instance = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload decoded response body; may be empty when the
     *                                         server answered with something other than JSON
     * @param int|null                $fallbackStatus HTTP status to use when the body carries none
     */
    public static function fromArray(array $payload, ?int $fallbackStatus = null): self
    {
        return new self(
            self::stringOrNull($payload['type'] ?? null),
            self::stringOrNull($payload['title'] ?? null),
            self::intOrNull($payload['status'] ?? null) ?? $fallbackStatus,
            self::stringOrNull($payload['detail'] ?? null),
            self::stringOrNull($payload['instance'] ?? null),
        );
    }

    public function type(): ?string
    {
        return $this->type;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function status(): ?int
    {
        return $this->status;
    }

    public function detail(): ?string
    {
        return $this->detail;
    }

    public function instance(): ?string
    {
        return $this->instance;
    }

    /**
     * Short human readable summary, preferring the specific `detail` over the generic `title`.
     */
    public function summary(): ?string
    {
        return $this->detail ?? $this->title;
    }

    /**
     * @return array{type: string|null, title: string|null, status: int|null, detail: string|null, instance: string|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'detail' => $this->detail,
            'instance' => $this->instance,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
