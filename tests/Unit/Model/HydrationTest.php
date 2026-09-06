<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RetJetApi\Returns\Model\Hydration;
use RetJetApi\Returns\Model\ItemCondition;

#[CoversClass(Hydration::class)]
final class HydrationTest extends TestCase
{
    public function testMissingKeysReadAsNull(): void
    {
        self::assertNull(Hydration::string([], 'a'));
        self::assertNull(Hydration::int([], 'a'));
        self::assertNull(Hydration::float([], 'a'));
        self::assertNull(Hydration::bool([], 'a'));
        self::assertNull(Hydration::object([], 'a'));
    }

    public function testExplicitNullsReadAsNull(): void
    {
        $data = ['a' => null];

        self::assertNull(Hydration::string($data, 'a'));
        self::assertNull(Hydration::int($data, 'a'));
        self::assertNull(Hydration::float($data, 'a'));
        self::assertNull(Hydration::bool($data, 'a'));
        self::assertNull(Hydration::object($data, 'a'));
    }

    public function testStringsAreNeverBuiltOutOfOtherTypes(): void
    {
        self::assertSame('x', Hydration::string(['a' => 'x'], 'a'));
        self::assertNull(Hydration::string(['a' => 7], 'a'), 'A number is a schema change, not a format detail.');
        self::assertNull(Hydration::string(['a' => ['x']], 'a'));
        self::assertNull(Hydration::string(['a' => true], 'a'));
    }

    public function testIntegersAcceptTheirNumericStringForm(): void
    {
        self::assertSame(7, Hydration::int(['a' => 7], 'a'));
        self::assertSame(-7, Hydration::int(['a' => '-7'], 'a'));
        self::assertNull(Hydration::int(['a' => '7.5'], 'a'));
        self::assertNull(Hydration::int(['a' => 'seven'], 'a'));
        self::assertNull(Hydration::int(['a' => true], 'a'));
    }

    public function testFloatsAcceptIntegersAndNumericStrings(): void
    {
        self::assertSame(1.5, Hydration::float(['a' => 1.5], 'a'));
        self::assertSame(2.0, Hydration::float(['a' => 2], 'a'), 'JSON drops a .0 fraction.');
        self::assertSame(129.99, Hydration::float(['a' => '129.99'], 'a'));
        self::assertNull(Hydration::float(['a' => 'free'], 'a'));
    }

    public function testBooleansAcceptTheZeroAndOneForm(): void
    {
        self::assertTrue(Hydration::bool(['a' => true], 'a'));
        self::assertFalse(Hydration::bool(['a' => false], 'a'));
        self::assertTrue(Hydration::bool(['a' => 1], 'a'));
        self::assertFalse(Hydration::bool(['a' => 0], 'a'));
        self::assertNull(Hydration::bool(['a' => 'yes'], 'a'));
        self::assertNull(Hydration::bool(['a' => 2], 'a'));
    }

    public function testObjectsAreReadWithStringKeys(): void
    {
        self::assertSame(['0' => 'a', '1' => 'b'], Hydration::object(['a' => ['a', 'b']], 'a'));
        self::assertSame(['k' => 'v'], Hydration::object(['a' => ['k' => 'v']], 'a'));
        self::assertNull(Hydration::object(['a' => 'scalar'], 'a'));
    }

    public function testNormaliseTurnsIntegerKeysIntoStrings(): void
    {
        self::assertSame(['1' => 'a', 'b' => 'c'], Hydration::normalise([1 => 'a', 'b' => 'c']));
    }

    public function testObjectsReadsAListOfNestedObjects(): void
    {
        $data = ['a' => [['k' => 'v1'], ['k' => 'v2']]];

        self::assertSame([['k' => 'v1'], ['k' => 'v2']], Hydration::objects($data, 'a'));
    }

    public function testObjectsIsNullWhenTheMemberIsMissingOrNotAList(): void
    {
        self::assertNull(Hydration::objects([], 'a'));
        self::assertNull(Hydration::objects(['a' => null], 'a'));
        self::assertNull(Hydration::objects(['a' => 'scalar'], 'a'));
    }

    /**
     * A malformed element (not itself an object) is dropped rather than turning the whole
     * member absent - but its position is not reused, so index N here still lines up with
     * index N in the untouched original list.
     */
    public function testObjectsDropsMalformedElementsWithoutReindexing(): void
    {
        $data = ['a' => [['k' => 'v0'], 'not-an-object', ['k' => 'v2']]];

        self::assertSame([0 => ['k' => 'v0'], 2 => ['k' => 'v2']], Hydration::objects($data, 'a'));
    }

    public function testNestedHydratesThroughTheGivenModel(): void
    {
        $data = ['a' => ['id' => 1, 'label' => 'new_unopened']];
        $condition = Hydration::nested($data, 'a', ItemCondition::class);

        self::assertInstanceOf(ItemCondition::class, $condition);
        self::assertSame(1, $condition->id);
        self::assertSame('new_unopened', $condition->label);
    }

    public function testNestedIsNullWhenTheMemberIsMissingOrNotAnObject(): void
    {
        self::assertNull(Hydration::nested([], 'a', ItemCondition::class));
        self::assertNull(Hydration::nested(['a' => 'scalar'], 'a', ItemCondition::class));
    }

    public function testNestedListHydratesEachElementThroughTheGivenModel(): void
    {
        $data = ['a' => [['id' => 1], ['id' => 2]]];
        $conditions = Hydration::nestedList($data, 'a', ItemCondition::class);

        self::assertNotNull($conditions);
        self::assertContainsOnlyInstancesOf(ItemCondition::class, $conditions);
        self::assertSame([1, 2], array_map(static fn (ItemCondition $c): ?int => $c->id, $conditions));
    }

    public function testNestedListIsNullWhenTheMemberIsMissingOrNotAList(): void
    {
        self::assertNull(Hydration::nestedList([], 'a', ItemCondition::class));
        self::assertNull(Hydration::nestedList(['a' => 'scalar'], 'a', ItemCondition::class));
    }

    /**
     * A malformed element does not shift the index of the ones that follow it, so a hydrated
     * list stays index-aligned with the raw list it came from.
     */
    public function testNestedListPreservesIndicesAcrossADroppedElement(): void
    {
        $data = ['a' => [['id' => 1], 'not-an-object', ['id' => 3]]];
        $conditions = Hydration::nestedList($data, 'a', ItemCondition::class);

        self::assertNotNull($conditions);
        self::assertSame([0, 2], array_keys($conditions));
        self::assertSame(1, $conditions[0]->id);
        self::assertSame(3, $conditions[2]->id);
    }
}
