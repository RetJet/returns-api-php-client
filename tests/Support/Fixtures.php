<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Support;

use RuntimeException;

/**
 * Loads the JSON fixtures in tests/Fixtures.
 *
 * The fixtures are built from the `example` values in resources/openapi.json - see
 * FixturesMatchSpecTest, which fails if a fixture drifts away from the frozen spec.
 */
final class Fixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function load(string $name): array
    {
        $path = self::path($name);
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Fixture "%s" could not be read.', $path));
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Fixture "%s" does not decode to an array.', $path));
        }

        $normalised = [];

        foreach ($decoded as $key => $value) {
            $normalised[(string) $key] = $value;
        }

        return $normalised;
    }

    /**
     * Wraps items in the Hydra envelope the API returns for a collection, so a resource test
     * can build a page out of the spec-derived item fixtures.
     *
     * @param list<array<string, mixed>> $member
     * @param array<string, string>      $view
     *
     * @return array<string, mixed>
     */
    public static function hydraCollection(array $member, ?int $totalItems = null, array $view = []): array
    {
        return [
            '@context' => '/contexts/Collection',
            '@id' => '/v1/collection',
            '@type' => 'Collection',
            'member' => $member,
            'totalItems' => $totalItems ?? count($member),
            'view' => $view,
        ];
    }

    public static function path(string $name): string
    {
        return dirname(__DIR__) . '/Fixtures/' . $name . '.json';
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        $names = [];

        foreach (glob(dirname(__DIR__) . '/Fixtures/*.json') ?: [] as $file) {
            $names[] = basename($file, '.json');
        }

        sort($names);

        return $names;
    }
}
