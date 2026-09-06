<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Request;

/**
 * A request body the caller assembles and the SDK serialises.
 *
 * Payloads differ from models in one deliberate way: toArray() omits members left unset,
 * where a model's toArray() keeps its nulls so it can round-trip. Sending an explicit null
 * for every field the caller never touched is a different statement to the server than not
 * sending the field at all, and only the latter is what "I did not set this" means.
 */
interface Payload
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
