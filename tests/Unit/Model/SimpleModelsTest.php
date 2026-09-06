<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Unit\Model;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RetJetApi\Returns\Model\ItemCondition;
use RetJetApi\Returns\Model\ItemReason;
use RetJetApi\Returns\Model\ItemResolution;
use RetJetApi\Returns\Model\Model;
use RetJetApi\Returns\Model\OrderedProduct;
use RetJetApi\Returns\Model\ReturnPoint;
use RetJetApi\Returns\Model\RmaRequest;
use RetJetApi\Returns\Model\RmaRequestCustomer;
use RetJetApi\Returns\Model\RmaRequestFile;
use RetJetApi\Returns\Model\RmaRequestFollower;
use RetJetApi\Returns\Model\RmaRequestItem;
use RetJetApi\Returns\Model\RmaRequestState;
use RetJetApi\Returns\Model\SaleChannel;
use RetJetApi\Returns\Model\TimelineEntry;
use RetJetApi\Returns\Tests\Support\Fixtures;

/**
 * Contract every model has to honour, checked against each model's fixture.
 */
#[CoversClass(ItemCondition::class)]
#[CoversClass(ItemReason::class)]
#[CoversClass(ItemResolution::class)]
#[CoversClass(OrderedProduct::class)]
#[CoversClass(ReturnPoint::class)]
#[CoversClass(RmaRequest::class)]
#[CoversClass(RmaRequestCustomer::class)]
#[CoversClass(RmaRequestFile::class)]
#[CoversClass(RmaRequestFollower::class)]
#[CoversClass(RmaRequestItem::class)]
#[CoversClass(RmaRequestState::class)]
#[CoversClass(SaleChannel::class)]
#[CoversClass(TimelineEntry::class)]
final class SimpleModelsTest extends TestCase
{
    /**
     * Every model in the SDK, paired with the fixture it hydrates from.
     *
     * @return iterable<string, array{class-string<Model>, string}>
     */
    public static function models(): iterable
    {
        yield 'RmaRequest' => [RmaRequest::class, 'rma_request'];
        yield 'RmaRequestState' => [RmaRequestState::class, 'rma_request_state'];
        yield 'RmaRequestCustomer' => [RmaRequestCustomer::class, 'rma_request_customer'];
        yield 'RmaRequestFollower' => [RmaRequestFollower::class, 'rma_request_follower'];
        yield 'RmaRequestItem' => [RmaRequestItem::class, 'rma_request_item'];
        yield 'RmaRequestFile' => [RmaRequestFile::class, 'rma_request_file'];
        yield 'OrderedProduct' => [OrderedProduct::class, 'ordered_product'];
        yield 'ReturnPoint' => [ReturnPoint::class, 'return_point'];
        yield 'SaleChannel' => [SaleChannel::class, 'sale_channel'];
        yield 'ItemCondition' => [ItemCondition::class, 'item_condition'];
        yield 'ItemReason' => [ItemReason::class, 'item_reason'];
        yield 'ItemResolution' => [ItemResolution::class, 'item_resolution'];
        yield 'TimelineEntry' => [TimelineEntry::class, 'timeline_entry'];
    }

    /**
     * @param class-string<Model> $model
     */
    #[DataProvider('models')]
    public function testRawReturnsThePayloadUntouched(string $model, string $fixture): void
    {
        $payload = Fixtures::load($fixture);

        self::assertSame($payload, $model::fromArray($payload)->raw());
    }

    /**
     * The JSON-LD members arrive on every object once Accept: application/ld+json is sent.
     * They must not appear among the typed members, and must stay readable.
     *
     * @param class-string<Model> $model
     */
    #[DataProvider('models')]
    public function testJsonLdMembersStayOutOfTheTypedShape(string $model, string $fixture): void
    {
        $hydrated = $model::fromArray(Fixtures::load($fixture));

        foreach (['@id', '@type', '@context'] as $member) {
            self::assertArrayNotHasKey($member, $hydrated->toArray());
            self::assertArrayHasKey($member, $hydrated->raw());
        }
    }

    /**
     * @param class-string<Model> $model
     */
    #[DataProvider('models')]
    public function testToArrayRoundTripsThroughFromArray(string $model, string $fixture): void
    {
        $hydrated = $model::fromArray(Fixtures::load($fixture));

        self::assertSame($hydrated->toArray(), $model::fromArray($hydrated->toArray())->toArray());
    }

    /**
     * @param class-string<Model> $model
     */
    #[DataProvider('models')]
    public function testAnEmptyPayloadIsAcceptedAndYieldsOnlyNulls(string $model): void
    {
        $empty = $model::fromArray([])->toArray();

        self::assertNotSame([], $empty, 'Every model types at least one member.');

        foreach ($empty as $key => $value) {
            self::assertNull($value, sprintf('%s::%s should be null for an empty payload.', $model, $key));
        }
    }

    public function testItemConditionHydratesFromItsFixture(): void
    {
        $condition = ItemCondition::fromArray(Fixtures::load('item_condition'));

        self::assertSame(1, $condition->id);
        self::assertSame('new_unopened', $condition->label);
        self::assertSame('New / Unopened', $condition->labelTranslated);
        self::assertTrue($condition->isActive);
        self::assertSame(1, $condition->position);
    }

    public function testItemReasonHydratesFromItsFixture(): void
    {
        $reason = ItemReason::fromArray(Fixtures::load('item_reason'));

        self::assertSame(1, $reason->id);
        self::assertSame('defective', $reason->label);
        self::assertSame('Product is defective', $reason->labelTranslated);
        self::assertTrue($reason->isActive);
        self::assertTrue($reason->isReturn);
        self::assertTrue($reason->isWarranty);
        self::assertSame(1, $reason->position);
        self::assertSame(1, $reason->returnPosition);
    }

    public function testItemResolutionHydratesFromItsFixture(): void
    {
        $resolution = ItemResolution::fromArray(Fixtures::load('item_resolution'));

        self::assertSame(1, $resolution->id);
        self::assertSame('refund', $resolution->label);
        self::assertSame('Full refund', $resolution->labelTranslated);
        self::assertTrue($resolution->isActive);
        self::assertTrue($resolution->isReturn);
        self::assertTrue($resolution->isWarranty);
        self::assertSame(1, $resolution->position);
        self::assertSame(1, $resolution->returnPosition);
        self::assertSame(1, $resolution->warrantyPosition);
    }

    public function testOrderedProductHydratesFromItsFixture(): void
    {
        $product = OrderedProduct::fromArray(Fixtures::load('ordered_product'));

        self::assertSame(42, $product->id);
        self::assertSame('Premium Wireless Headphones', $product->name);
        self::assertSame('https://cdn.example.com/products/headphones-001.jpg', $product->cover);
        self::assertSame('WH-PRO-001', $product->sku);
        self::assertSame(299.99, $product->price);
        self::assertSame('PLN', $product->currency);
        self::assertSame(1, $product->quantity);
        self::assertSame('ORD-2024-5678', $product->remoteOrder);
        self::assertSame('6889645408334', $product->orderId);
        self::assertSame('SKU-9', $product->productId);
        self::assertSame('LINE-2', $product->lineId);
    }

    public function testReturnPointHydratesFromItsFixture(): void
    {
        $point = ReturnPoint::fromArray(Fixtures::load('return_point'));

        self::assertSame(10, $point->id);
        self::assertSame('Warsaw Warehouse', $point->customLabel);
        self::assertSame('ACME Returns Dept.', $point->name);
        self::assertSame('PL', $point->country);
        self::assertSame('Mazowieckie', $point->state);
        self::assertSame('Warsaw', $point->city);
        self::assertSame('02-673', $point->zip);
        self::assertSame('ul. Konstruktorska 10', $point->address1);
        self::assertSame('Building B, Gate 3', $point->address2);
        self::assertSame('+48221234567', $point->contactPhone);
        self::assertSame('returns@acme.com', $point->contactEmail);
    }

    public function testSaleChannelHydratesFromItsFixture(): void
    {
        $channel = SaleChannel::fromArray(Fixtures::load('sale_channel'));

        self::assertSame(1, $channel->id);
        self::assertSame('My Shopify Store', $channel->label);
        self::assertSame('shopify-main', $channel->name);
        self::assertSame('shopify', $channel->channelType);
        self::assertSame(30, $channel->maxReturnDaysProcessingPolicy);
        self::assertSame(365, $channel->maxWarrantyDaysProcessingPolicy);
        self::assertSame(154, $channel->returnPointId);
        self::assertInstanceOf(ReturnPoint::class, $channel->returnPointAddress);
        self::assertSame('Warsaw Warehouse', $channel->returnPointAddress->customLabel);
    }

    public function testRmaRequestStateHydratesFromItsFixture(): void
    {
        $state = RmaRequestState::fromArray(Fixtures::load('rma_request_state'));

        self::assertSame('new', $state->label);
        self::assertSame('New', $state->labelTranslated);
        self::assertSame('new', $state->state);
        self::assertSame('#3b82f6', $state->labelColor);
    }

    public function testRmaRequestCustomerHydratesFromItsFixture(): void
    {
        $customer = RmaRequestCustomer::fromArray(Fixtures::load('rma_request_customer'));

        self::assertSame('john.doe@example.com', $customer->email);
        self::assertSame('John', $customer->firstName);
        self::assertSame('Doe', $customer->lastName);
        self::assertSame('PL1234567890', $customer->taxId);
        self::assertSame('PL', $customer->country);
        self::assertSame('Mazowieckie', $customer->state);
        self::assertSame('Warsaw', $customer->city);
        self::assertSame('00-001', $customer->zip);
        self::assertSame('ul. Marszalkowska 1', $customer->address1);
        self::assertSame('apt. 5', $customer->address2);
        self::assertSame('+48123456789', $customer->phone);
        self::assertSame('ACME Sp. z o.o.', $customer->company);

        self::assertArrayHasKey('firstName', $customer->toArray());
        self::assertArrayHasKey('taxId', $customer->toArray());
    }

    /**
     * Both members describe the user, never the RMA request: the spec calls `id` the
     * "User ID of the follower" and `userId` the "User ID to follow/unfollow". It gives them
     * different examples, so the fixture keeps them apart; which one a live response fills in
     * is still unconfirmed.
     */
    public function testRmaRequestFollowerHydratesFromItsFixture(): void
    {
        $follower = RmaRequestFollower::fromArray(Fixtures::load('rma_request_follower'));

        self::assertSame(2106, $follower->id);
        self::assertSame('agent@company.com', $follower->email);
        self::assertSame('John Agent', $follower->name);
        self::assertSame(42, $follower->userId);
    }

    public function testRmaRequestItemHydratesFromItsFixtureIncludingTheOrderedProduct(): void
    {
        $item = RmaRequestItem::fromArray(Fixtures::load('rma_request_item'));

        self::assertSame(567, $item->id);
        self::assertSame(2, $item->requestedQty);
        self::assertSame('damaged_product', $item->label);
        self::assertSame('pending', $item->state);
        self::assertSame('https://example.com/', $item->rmaRequestItemReason);
        self::assertSame('https://example.com/', $item->rmaRequestIItemResolution);
        self::assertSame('https://example.com/', $item->rmaRequestIItemCondition);

        self::assertInstanceOf(OrderedProduct::class, $item->orderedProduct);
        self::assertSame('SKU-9', $item->orderedProduct->productId);
    }

    public function testRmaRequestFileHydratesFromItsFixture(): void
    {
        $file = RmaRequestFile::fromArray(Fixtures::load('rma_request_file'));

        self::assertSame(
            'https://files.retjet.dev/file/10bee370-86fd-43d4-8389-9937456da0c5.pdf',
            $file->url,
        );
        self::assertSame('application/pdf', $file->mime);
    }

    public function testTimelineEntryHydratesFromItsFixture(): void
    {
        $entry = TimelineEntry::fromArray(Fixtures::load('timeline_entry'));

        self::assertSame(2106, $entry->id);
        self::assertSame('status_change', $entry->type);
        self::assertSame(1709913600, $entry->createdAt);
        self::assertSame('Status changed from "New" to "In Progress"', $entry->description);
        self::assertTrue($entry->public);
    }

    public function testTimelineEntryCreatedAtConvertsToUtcDateTimeImmutable(): void
    {
        $entry = TimelineEntry::fromArray(Fixtures::load('timeline_entry'));

        self::assertEquals(new DateTimeImmutable('@1709913600'), $entry->createdAtAsDateTime());
        self::assertNull(TimelineEntry::fromArray([])->createdAtAsDateTime());
    }

    /**
     * user and data are declared as string[] in a spec that is demonstrably wrong about the
     * other nested members too, so they stay in raw() until a live response settles it.
     */
    public function testTimelineEntryLeavesUserAndDataInRaw(): void
    {
        $entry = TimelineEntry::fromArray(Fixtures::load('timeline_entry'));

        self::assertArrayNotHasKey('user', $entry->toArray());
        self::assertArrayNotHasKey('data', $entry->toArray());
        self::assertArrayHasKey('user', $entry->raw());
        self::assertArrayHasKey('data', $entry->raw());
    }
}
