<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RetJetApi\Returns\Tests\Support\Fixtures;

/**
 * Proves the fixtures are derived from resources/openapi.json rather than invented.
 *
 * The spec carries no whole-object examples - only per-property `example` values - so the
 * fixtures were assembled from those. This test re-checks that assembly: every non-null
 * example in a schema must appear verbatim in the matching fixture, and a fixture may not
 * contain a member the schema does not declare.
 */
#[CoversNothing]
final class FixturesMatchSpecTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $schemas;

    public static function setUpBeforeClass(): void
    {
        $path = dirname(__DIR__, 3) . '/resources/openapi.json';
        $decoded = json_decode((string) file_get_contents($path), true);

        self::assertIsArray($decoded);

        $components = $decoded['components'] ?? null;

        self::assertIsArray($components);

        $schemas = $components['schemas'] ?? null;

        self::assertIsArray($schemas);

        $normalised = [];

        foreach ($schemas as $name => $schema) {
            $normalised[(string) $name] = $schema;
        }

        self::$schemas = $normalised;
    }

    /**
     * Fixture name => schema name in components/schemas.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function fixtures(): iterable
    {
        yield 'rma_request' => ['rma_request', 'RmaRequest'];
        yield 'rma_request_state' => ['rma_request_state', 'RmaRequestState'];
        yield 'rma_request_customer' => ['rma_request_customer', 'RmaRequestCustomer'];
        yield 'rma_request_follower' => ['rma_request_follower', 'RmaRequestFollower'];
        yield 'ordered_product' => ['ordered_product', 'OrderedProduct'];
        yield 'return_point' => ['return_point', 'ReturnPoint'];
        yield 'sale_channel' => ['sale_channel', 'SaleChannel'];
        yield 'item_condition' => ['item_condition', 'ItemCondition'];
        yield 'item_reason' => ['item_reason', 'ItemReason'];
        yield 'item_resolution' => ['item_resolution', 'ItemResolution'];
        yield 'timeline_entry' => ['timeline_entry', 'RmaRequestTimeline'];
        yield 'rma_request_item' => ['rma_request_item', 'RmaRequestItem'];
        yield 'rma_request_file' => ['rma_request_file', 'RmaRequestFile'];
    }

    #[DataProvider('fixtures')]
    public function testEverySpecExampleAppearsInTheFixture(string $fixture, string $schema): void
    {
        $data = Fixtures::load($fixture);
        $checked = 0;

        foreach ($this->properties($schema) as $property => $definition) {
            $example = $definition['example'] ?? null;

            if ($example === null) {
                continue;
            }

            self::assertArrayHasKey($property, $data, sprintf('%s is missing "%s".', $fixture, $property));
            self::assertSame($example, $data[$property], sprintf('%s.%s drifted from the spec example.', $fixture, $property));
            ++$checked;
        }

        self::assertGreaterThan(0, $checked, sprintf('%s has no spec examples to check against.', $schema));
    }

    #[DataProvider('fixtures')]
    public function testTheFixtureInventsNoMembers(string $fixture, string $schema): void
    {
        $properties = $this->properties($schema);

        foreach (array_keys(Fixtures::load($fixture)) as $key) {
            if (str_starts_with($key, '@')) {
                continue;
            }

            self::assertArrayHasKey($key, $properties, sprintf('%s declares "%s", the spec does not.', $fixture, $key));
        }
    }

    /**
     * The nested objects the fixtures embed are the ones the spec says are there: RmaRequest
     * types customer and state as anyOf[$ref, null], not as scalars.
     */
    #[DataProvider('nestedObjectProperties')]
    public function testNestedObjectsMatchTheSchemaReference(string $property, string $expectedRef): void
    {
        $definition = $this->properties('RmaRequest')[$property] ?? null;

        self::assertIsArray($definition);

        $anyOf = $definition['anyOf'] ?? null;

        self::assertIsArray($anyOf);
        self::assertSame(
            ['#/components/schemas/' . $expectedRef, null],
            [
                is_array($anyOf[0] ?? null) ? ($anyOf[0]['$ref'] ?? null) : null,
                is_array($anyOf[1] ?? null) ? ($anyOf[1]['$ref'] ?? null) : null,
            ],
        );

        $nested = Fixtures::load('rma_request')[$property] ?? null;

        self::assertIsArray($nested, sprintf('The fixture must embed %s as an object.', $property));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nestedObjectProperties(): iterable
    {
        yield 'customer' => ['customer', 'RmaRequestCustomer'];
        yield 'state' => ['state', 'RmaRequestState'];
        yield 'saleChannel' => ['saleChannel', 'SaleChannel'];
    }

    /**
     * `items`, `confirmations` and `attachments` used to be declared as string[] while
     * plainly being objects in practice. The spec now names the real schemas; this pins that
     * so a future regression back to string[] is visible rather than silently un-typing the
     * SDK's models.
     */
    #[DataProvider('unconfirmedArrayProperties')]
    public function testThePreviouslyUnconfirmedArrayMembersNowReferenceTheirSchemas(
        string $property,
        string $expectedRef,
    ): void {
        $definition = $this->properties('RmaRequest')[$property] ?? null;

        self::assertIsArray($definition);
        self::assertSame('array', $definition['type'] ?? null);
        self::assertSame(
            ['$ref' => '#/components/schemas/' . $expectedRef],
            $definition['items'] ?? null,
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unconfirmedArrayProperties(): iterable
    {
        yield 'items' => ['items', 'RmaRequestItem'];
        yield 'confirmations' => ['confirmations', 'RmaRequestFile'];
        yield 'attachments' => ['attachments', 'RmaRequestFile'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function properties(string $schema): array
    {
        $definition = self::$schemas[$schema] ?? null;

        self::assertIsArray($definition, sprintf('Schema "%s" is missing from the spec.', $schema));

        $properties = $definition['properties'] ?? null;

        self::assertIsArray($properties);

        $normalised = [];

        foreach ($properties as $name => $property) {
            self::assertIsArray($property);

            $values = [];

            foreach ($property as $key => $value) {
                $values[(string) $key] = $value;
            }

            $normalised[(string) $name] = $values;
        }

        return $normalised;
    }
}
