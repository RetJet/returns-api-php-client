<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Model;

/**
 * The contract every model in this SDK follows.
 *
 * Models are readonly value objects with nullable properties throughout: no schema in the
 * API declares a `required` member, so promising a non-null value anywhere would be a
 * guarantee the server does not make.
 *
 * Hydration never fails and never drops data. Anything the SDK does not type - unknown keys
 * added by a future API version, the JSON-LD members (`@id`, `@type`, `@context`), and the
 * nested structures whose shape is not confirmed yet - stays reachable through raw().
 */
interface Model
{
    /**
     * Builds the model from one decoded API object. Values of an unexpected type are
     * treated as absent rather than coerced, so a type change upstream degrades to null
     * instead of producing nonsense.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static;

    /**
     * The typed members of this model, keyed the way the API keys them, so the result can
     * be handed straight back to fromArray(). Nested models are converted recursively.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * The complete payload this model was built from, untouched.
     *
     * @return array<string, mixed>
     */
    public function raw(): array;
}
