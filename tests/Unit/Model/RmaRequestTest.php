<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Unit\Model;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RetJetApi\Returns\Model\Hydration;
use RetJetApi\Returns\Model\RmaRequest;
use RetJetApi\Returns\Model\RmaRequestCustomer;
use RetJetApi\Returns\Model\RmaRequestFile;
use RetJetApi\Returns\Model\RmaRequestItem;
use RetJetApi\Returns\Model\RmaRequestState;
use RetJetApi\Returns\Model\SaleChannel;
use RetJetApi\Returns\Tests\Support\Fixtures;

#[CoversClass(RmaRequest::class)]
#[CoversClass(Hydration::class)]
final class RmaRequestTest extends TestCase
{
    public function testItHydratesTheScalarMembers(): void
    {
        $rma = RmaRequest::fromArray(Fixtures::load('rma_request'));

        self::assertSame(1234, $rma->id);
        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $rma->uuid);
        self::assertSame('RMA-2024-001234', $rma->identifier);
        self::assertSame('return', $rma->type);
        self::assertSame(1709913600, $rma->createdAt);
        self::assertSame(1711123200, $rma->deadlineTs);
        self::assertSame('pl', $rma->customerLocale);
        self::assertSame('Product arrived damaged, packaging was crushed during shipping.', $rma->customerInfo);
        self::assertSame('PL61109010140000071219812874', $rma->refundBankAccountNo);
        self::assertSame(129.99, $rma->totalRequestedAmount);
        self::assertSame('PLN', $rma->totalRequestedCurrency);
        self::assertSame(99.99, $rma->totalConfirmedAmount);
        self::assertSame('PLN', $rma->totalConfirmedCurrency);
    }

    /**
     * saleChannel is an embedded object, not the IRI reference earlier API versions sent.
     */
    public function testTheSaleChannelIsHydratedAsAnEmbeddedObject(): void
    {
        $rma = RmaRequest::fromArray(Fixtures::load('rma_request'));

        self::assertInstanceOf(SaleChannel::class, $rma->saleChannel);
        self::assertSame('My Shopify Store', $rma->saleChannel->label);
    }

    public function testItHydratesTheNestedCustomerAndState(): void
    {
        $rma = RmaRequest::fromArray(Fixtures::load('rma_request'));

        self::assertInstanceOf(RmaRequestCustomer::class, $rma->customer);
        self::assertSame('john.doe@example.com', $rma->customer->email);
        self::assertSame('John', $rma->customer->firstName);

        self::assertInstanceOf(RmaRequestState::class, $rma->state);
        self::assertSame('new', $rma->state->label);
        self::assertSame('#3b82f6', $rma->state->labelColor);
    }

    public function testMissingNestedObjectsStayNull(): void
    {
        $rma = RmaRequest::fromArray(['id' => 1]);

        self::assertNull($rma->customer);
        self::assertNull($rma->state);
        self::assertSame(1, $rma->id);
    }

    public function testAnEmptyPayloadHydratesToAnAllNullModel(): void
    {
        $rma = RmaRequest::fromArray([]);

        self::assertNull($rma->id);
        self::assertNull($rma->identifier);
        self::assertNull($rma->totalRequestedAmount);
        self::assertSame([], $rma->raw());
    }

    /**
     * Nothing is dropped during hydration, so a member the SDK does not type - including the
     * JSON-LD ones - is still reachable.
     */
    public function testRawKeepsTheWholePayloadIncludingJsonLdMembers(): void
    {
        $fixture = Fixtures::load('rma_request');
        $rma = RmaRequest::fromArray($fixture);

        self::assertSame($fixture, $rma->raw());
        self::assertSame('/v1/rma-requests/1234', $rma->raw()['@id']);
        self::assertSame('RmaRequest', $rma->raw()['@type']);
    }

    /**
     * items / confirmations / attachments used to be members the spec got wrong (declared as
     * string[], in practice objects) and were reachable only through raw(). The spec now names
     * their real schemas, so they hydrate into typed lists like every other nested member.
     */
    public function testItemsConfirmationsAndAttachmentsHydrateIntoTypedLists(): void
    {
        $fixture = Fixtures::load('rma_request_with_nested_objects');
        $rma = RmaRequest::fromArray($fixture);

        self::assertNotNull($rma->items);
        self::assertNotNull($rma->confirmations);
        self::assertNotNull($rma->attachments);
        self::assertContainsOnlyInstancesOf(RmaRequestItem::class, $rma->items);
        self::assertContainsOnlyInstancesOf(RmaRequestFile::class, $rma->confirmations);
        self::assertContainsOnlyInstancesOf(RmaRequestFile::class, $rma->attachments);

        self::assertSame(55, $rma->items[0]->id);
        self::assertSame(
            'https://files.retjet.dev/file/10bee370-86fd-43d4-8389-9937456da0c5.pdf',
            $rma->confirmations[0]->url,
        );
        self::assertSame('https://cdn.example.com/rma/1234/damage.jpg', $rma->attachments[0]->url);

        // The original payload survives untouched regardless of how it was typed.
        self::assertSame($fixture['items'], $rma->raw()['items']);
    }

    /**
     * This API has already changed a nested member from an IRI string to an embedded object
     * once (saleChannel). Should it happen again for items[].orderedProduct, hydration must not
     * throw or invent a partial object - it degrades to null like any other value of the wrong
     * type, and the original string stays readable through raw(). This fixture's first item
     * deliberately keeps orderedProduct as the pre-migration IRI string to pin that.
     */
    public function testAnItemsOrderedProductThatIsStillAnIriDegradesToNullRatherThanThrowing(): void
    {
        $fixture = Fixtures::load('rma_request_with_nested_objects');
        $rma = RmaRequest::fromArray($fixture);

        self::assertNotNull($rma->items);
        self::assertNull($rma->items[0]->orderedProduct);
        self::assertSame('/v1/ordered-products/42', $rma->items[0]->raw()['orderedProduct']);
    }

    /**
     * An RmaRequest with no items/confirmations/attachments member at all stays null - a
     * different statement than "there are none of them".
     */
    public function testMissingArrayMembersStayNullRatherThanEmpty(): void
    {
        $rma = RmaRequest::fromArray(['id' => 1234]);

        self::assertNull($rma->items);
        self::assertNull($rma->confirmations);
        self::assertNull($rma->attachments);
    }

    /**
     * Forward compatibility: a member the API adds after this SDK shipped must not break
     * hydration, and must still be readable.
     */
    public function testAnUnknownMemberDoesNotBreakHydration(): void
    {
        $rma = RmaRequest::fromArray(Fixtures::load('rma_request_with_nested_objects'));

        self::assertSame(1234, $rma->id);
        self::assertSame(['added' => 'by the API after this SDK shipped'], $rma->raw()['unknownFutureField']);
    }

    public function testToArrayUsesTheApiKeysAndRoundTrips(): void
    {
        $rma = RmaRequest::fromArray(Fixtures::load('rma_request'));
        $array = $rma->toArray();

        self::assertSame(1234, $array['id']);

        $customer = $array['customer'];

        self::assertIsArray($customer);
        self::assertSame('john.doe@example.com', $customer['email']);
        self::assertArrayHasKey('firstName', $customer, 'toArray() emits the API key.');

        self::assertSame($rma->toArray(), RmaRequest::fromArray($array)->toArray());
    }

    public function testTheUnixTimestampsConvertToUtcDateTimeImmutable(): void
    {
        $rma = RmaRequest::fromArray(Fixtures::load('rma_request'));

        self::assertEquals(new DateTimeImmutable('@1709913600'), $rma->createdAtAsDateTime());
        self::assertEquals(new DateTimeImmutable('@1711123200'), $rma->deadlineTsAsDateTime());
    }

    public function testMissingTimestampsConvertToNull(): void
    {
        $rma = RmaRequest::fromArray([]);

        self::assertNull($rma->createdAtAsDateTime());
        self::assertNull($rma->deadlineTsAsDateTime());
    }

    public function testValuesOfTheWrongTypeDegradeToNullInsteadOfNonsense(): void
    {
        $rma = RmaRequest::fromArray([
            'id' => 'not-a-number',
            'identifier' => 42,
            'totalRequestedAmount' => 'free',
            'customer' => 'not-an-object',
        ]);

        self::assertNull($rma->id);
        self::assertNull($rma->identifier);
        self::assertNull($rma->totalRequestedAmount);
        self::assertNull($rma->customer);
        self::assertSame('not-an-object', $rma->raw()['customer'], 'The original value stays readable.');
    }
}
