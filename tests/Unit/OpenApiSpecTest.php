<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Guards resources/openapi.json - the frozen spec every later stage builds on.
 *
 * These assertions are deliberately about shape, not content: they catch a truncated
 * download or a silently reshaped spec, without breaking every time the API adds a field.
 */
#[CoversNothing]
final class OpenApiSpecTest extends TestCase
{
    private const EXPECTED_OPERATIONS = 34;
    private const EXPECTED_PATHS = 30;

    /** @var array<string, mixed> */
    private static array $spec;

    public static function setUpBeforeClass(): void
    {
        $path = dirname(__DIR__, 2) . '/resources/openapi.json';

        self::assertFileExists($path, 'Run "php tools/fetch-openapi.php --base-uri=..." to create it.');

        /** @var array<string, mixed> $spec */
        $spec = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::$spec = $spec;
    }

    public function testItIsAnOpenApi31Document(): void
    {
        self::assertSame('3.1.0', self::$spec['openapi'] ?? null);
        self::assertSame('RetJet API', self::dig(self::$spec, ['info'])['title'] ?? null);
    }

    public function testItHasTheExpectedNumberOfPathsAndOperations(): void
    {
        self::assertCount(self::EXPECTED_PATHS, $this->paths());
        self::assertSame(self::EXPECTED_OPERATIONS, $this->countOperations());
    }

    /**
     * The paths the client is built against. Renaming any of these upstream is a breaking
     * change the SDK has to react to, so it should fail loudly here rather than at runtime.
     */
    public function testItExposesTheEndpointsTheClientTargets(): void
    {
        $expected = [
            '/v1/rma-requests',
            '/v1/rma-requests/{id}',
            '/v1/rma-requests/{requestId}/status',
            '/v1/rma-requests/bulk/status',
            '/v1/sale-channels',
            '/v1/return-points',
            '/v1/ordered-products',
        ];

        foreach ($expected as $path) {
            self::assertArrayHasKey($path, $this->paths());
        }
    }

    /**
     * Collections must be requested as application/ld+json - the plain JSON variant returns
     * a bare array with no totalItems and no view links, which makes pagination impossible.
     */
    public function testCollectionResponsesOfferTheHydraContentType(): void
    {
        $content = self::dig(
            $this->paths(),
            ['/v1/rma-requests', 'get', 'responses', '200', 'content'],
        );

        self::assertArrayHasKey('application/ld+json', $content);
    }

    /** @return array<array-key, mixed> */
    private function paths(): array
    {
        return self::dig(self::$spec, ['paths']);
    }

    /**
     * Walks a nested key path, returning an empty array as soon as a segment is missing
     * or is not itself an array. Keeps the assertions above readable without chaining
     * offsets onto values the spec does not guarantee.
     *
     * @param array<array-key, mixed> $data
     * @param list<string> $keys
     * @return array<array-key, mixed>
     */
    private static function dig(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            $next = $data[$key] ?? null;

            if (!is_array($next)) {
                return [];
            }

            $data = $next;
        }

        return $data;
    }

    private function countOperations(): int
    {
        $methods = ['get', 'post', 'put', 'patch', 'delete'];
        $count = 0;

        foreach ($this->paths() as $operations) {
            if (is_array($operations)) {
                $count += count(array_intersect(array_keys($operations), $methods));
            }
        }

        return $count;
    }
}
