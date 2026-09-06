<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Http;

/**
 * Strips credentials out of text that is about to be shown to somebody.
 *
 * @internal
 */
final class Redact
{
    public const REDACTED = '***';

    /**
     * Removes the userinfo component from any URI in $text.
     *
     * A base URI may legitimately carry credentials (`https://user:pass@host`), and those end
     * up inside exception messages that get logged, reported to error trackers and printed in
     * stack traces. Masking request headers is pointless while the same secret travels in the
     * message body of an exception.
     */
    public static function credentials(string $text): string
    {
        return (string) preg_replace(
            '#([a-z][a-z0-9+.-]*://)[^/\s@]+@#i',
            '$1' . self::REDACTED . '@',
            $text,
        );
    }
}
