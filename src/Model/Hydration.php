<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Model;

use DateTimeImmutable;

/**
 * Type-narrowing helpers shared by the models.
 *
 * Every reader answers null when the key is missing or holds something of the wrong type.
 * The numeric readers accept the representations JSON and PHP disagree about - an integral
 * float, a numeric string - because those are format differences, not type changes. A string
 * is never built out of a number, though: that would paper over a real schema change.
 *
 * @internal
 */
final class Hydration
{
    /**
     * @param array<string, mixed> $data
     */
    public static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function float(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function bool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === 1) {
            return $value === 1;
        }

        return null;
    }

    /**
     * A Unix-second timestamp as a UTC `DateTimeImmutable`, or null when there is none.
     * Shared by every model's *AsDateTime() accessor so the conversion lives in one place.
     */
    public static function unixTimestamp(?int $seconds): ?DateTimeImmutable
    {
        return $seconds === null ? null : new DateTimeImmutable('@' . $seconds);
    }

    /**
     * Reads a nested object, normalising its keys so the nested model gets the same
     * array<string, mixed> shape fromArray() promises.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    public static function object(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? self::normalise($value) : null;
    }

    /**
     * Reads a list of nested objects, normalising each one's keys the same way object() does.
     *
     * A value that is not a list, or absent altogether, hydrates to null rather than an empty
     * list: those are different statements the API can make ("this member does not apply" vs.
     * "it applies and there are none"), and only the former is what a missing key means.
     * An element that is not itself an object is dropped rather than treated as absent - one
     * malformed entry should not erase every entry that follows it. The original array keys
     * are kept rather than reindexed, so a dropped entry does not shift the position of the
     * ones after it: index N here still lines up with index N in raw()'s untouched list.
     *
     * @param array<string, mixed> $data
     *
     * @return array<int, array<string, mixed>>|null
     */
    public static function objects(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        if (!is_array($value)) {
            return null;
        }

        $objects = [];
        $index = 0;

        foreach ($value as $item) {
            if (is_array($item)) {
                $objects[$index] = self::normalise($item);
            }

            ++$index;
        }

        return $objects;
    }

    /**
     * Reads a nested object and hydrates it through the given model in one step - the
     * `$x = object(...); $x === null ? null : Model::fromArray($x)` dance every model with a
     * nested member would otherwise repeat.
     *
     * @template TModel of Model
     *
     * @param array<string, mixed> $data
     * @param class-string<TModel> $model
     *
     * @return TModel|null
     */
    public static function nested(array $data, string $key, string $model): ?Model
    {
        $object = self::object($data, $key);

        return $object === null ? null : $model::fromArray($object);
    }

    /**
     * Reads a list of nested objects and hydrates each one through the given model. Null and
     * index-preservation semantics are exactly objects()'s.
     *
     * @template TModel of Model
     *
     * @param array<string, mixed> $data
     * @param class-string<TModel> $model
     *
     * @return array<int, TModel>|null
     */
    public static function nestedList(array $data, string $key, string $model): ?array
    {
        $objects = self::objects($data, $key);

        if ($objects === null) {
            return null;
        }

        $hydrated = [];

        foreach ($objects as $index => $object) {
            $hydrated[$index] = $model::fromArray($object);
        }

        return $hydrated;
    }

    /**
     * JSON object keys that look like integers decode to PHP integer keys; normalising them
     * back to strings is what keeps the array<string, mixed> types honest.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function normalise(array $data): array
    {
        $normalised = [];

        foreach ($data as $key => $value) {
            $normalised[(string) $key] = $value;
        }

        return $normalised;
    }
}
