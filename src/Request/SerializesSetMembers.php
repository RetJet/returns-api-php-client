<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Request;

/**
 * Shared body of every Payload::toArray(): drop the members left null, then let `$extra` fill
 * the gaps without overwriting one that was actually set.
 *
 * A plain `array_filter($body)` would do the wrong thing here - its default callback drops any
 * falsy value, not just `null`, which would silently swallow a caller's `0`, `0.0`, `''` or
 * `false`. Centralising the correct comparison in one place means a new Payload gets it right
 * by using the trait, rather than by copying a lambda correctly.
 */
trait SerializesSetMembers
{
    /**
     * @param array<string, mixed> $body  named members, including the ones still null
     * @param array<string, mixed> $extra fills gaps left by members that are still null; a
     *                                    member present in $body with a non-null value wins
     *                                    over the same key here
     *
     * @return array<string, mixed>
     */
    private static function withExtra(array $body, array $extra): array
    {
        return array_filter($body, static fn (mixed $value): bool => $value !== null) + $extra;
    }
}
