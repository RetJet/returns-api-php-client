<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * PSR-3 logger that keeps every record so a test can inspect what was written - including
 * proving what was *not* written.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $records = [];

    /**
     * @param mixed                $level
     * @param string|Stringable    $message
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => is_string($level) ? $level : gettype($level),
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function recordsAtLevel(string $level): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (array $record): bool => $record['level'] === $level,
        ));
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(
            static fn (array $record): string => $record['message'],
            $this->records,
        );
    }

    /**
     * Everything written, flattened into one string per scalar found anywhere in any record -
     * message, context keys and context values, however deeply nested. This is what makes it
     * possible to assert that a secret appears nowhere at all, rather than merely that one
     * expected field was renamed.
     *
     * @return list<string>
     */
    public function flattened(): array
    {
        $flat = [];

        foreach ($this->records as $record) {
            $flat[] = $record['level'];
            $flat[] = $record['message'];
            self::flatten($record['context'], $flat);
        }

        return $flat;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string>            $flat
     */
    private static function flatten(array $data, array &$flat): void
    {
        foreach ($data as $key => $value) {
            $flat[] = (string) $key;

            if (is_array($value)) {
                self::flatten($value, $flat);

                continue;
            }

            if ($value === null) {
                continue;
            }

            if (is_object($value)) {
                $flat[] = $value::class;
                $flat[] = method_exists($value, '__toString') ? (string) $value : '';

                continue;
            }

            $flat[] = is_scalar($value) ? (string) $value : gettype($value);
        }
    }
}
