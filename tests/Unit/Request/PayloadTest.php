<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Unit\Request;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RetJetApi\Returns\Request\CreateRmaRequest;
use RetJetApi\Returns\Request\CreateRmaRequestCustomer;
use RetJetApi\Returns\Request\CreateRmaRequestItem;
use RetJetApi\Returns\Request\Payload;
use RetJetApi\Returns\Request\UpdateProduct;

#[CoversClass(CreateRmaRequest::class)]
#[CoversClass(CreateRmaRequestCustomer::class)]
#[CoversClass(CreateRmaRequestItem::class)]
#[CoversClass(UpdateProduct::class)]
final class PayloadTest extends TestCase
{
    private function customer(): CreateRmaRequestCustomer
    {
        return new CreateRmaRequestCustomer(
            email: 'john.doe@example.com',
            firstName: 'John',
            lastName: 'Doe',
            country: 'PL',
            city: 'Warszawa',
            address1: 'Prosta 1',
        );
    }

    private function item(): CreateRmaRequestItem
    {
        return new CreateRmaRequestItem(
            orderId: 'ORDER-123',
            productId: 'SKU-9',
            quantity: 2,
            reasonId: 3,
            conditionId: 4,
        );
    }

    /**
     * A payload omits what the caller never set. Sending an explicit null for every untouched
     * field says something different to the server than not sending the field at all.
     */
    public function testAnEmptyPayloadSerialisesToAnEmptyBody(): void
    {
        self::assertSame([], (new UpdateProduct())->toArray());
    }

    /**
     * saleChannelId, customer and items are required by the schema, so the SDK requires them
     * too - only the fields beyond those are optional and can be left unset.
     */
    public function testCreateRmaRequestSerialisesOnlyWhatWasSet(): void
    {
        $payload = new CreateRmaRequest(
            saleChannelId: 7,
            customer: $this->customer(),
            items: [$this->item()],
        );

        self::assertSame([
            'saleChannelId' => 7,
            'customer' => [
                'email' => 'john.doe@example.com',
                'firstName' => 'John',
                'lastName' => 'Doe',
                'country' => 'PL',
                'city' => 'Warszawa',
                'address1' => 'Prosta 1',
            ],
            'items' => [[
                'orderId' => 'ORDER-123',
                'productId' => 'SKU-9',
                'quantity' => 2,
                'reasonId' => 3,
                'conditionId' => 4,
            ]],
        ], $payload->toArray());
    }

    public function testCreateRmaRequestCarriesEveryMemberItNames(): void
    {
        $payload = new CreateRmaRequest(
            saleChannelId: 7,
            customer: $this->customer(),
            items: [$this->item()],
            type: 'return',
            customerInfo: 'Product arrived damaged.',
            customerInstruction: 'Please refund to the card',
            refundBankAccountNo: 'PL61109010140000071219812874',
        );

        $body = $payload->toArray();

        self::assertSame('return', $body['type']);
        self::assertSame('Product arrived damaged.', $body['customerInfo']);
        self::assertSame('Please refund to the card', $body['customerInstruction']);
        self::assertSame('PL61109010140000071219812874', $body['refundBankAccountNo']);
    }

    /**
     * Forward compatibility: a member the API grows after this release is reachable without
     * waiting for an SDK update.
     */
    public function testExtraMembersAreMergedIn(): void
    {
        $payload = new CreateRmaRequest(
            saleChannelId: 7,
            customer: $this->customer(),
            items: [$this->item()],
            extra: ['newServerField' => 'value'],
        );

        self::assertSame('value', $payload->toArray()['newServerField']);
    }

    /**
     * A named member wins over the same key in $extra - otherwise the typed API would be the
     * weaker of the two.
     */
    public function testANamedMemberIsNotOverwrittenByExtra(): void
    {
        $payload = new CreateRmaRequest(
            saleChannelId: 7,
            customer: $this->customer(),
            items: [$this->item()],
            type: 'return',
            extra: ['type' => 'warranty'],
        );

        self::assertSame('return', $payload->toArray()['type']);
    }

    public function testCreateRmaRequestCustomerSerialisesOnlyWhatWasSet(): void
    {
        $payload = new CreateRmaRequestCustomer(
            email: 'john.doe@example.com',
            firstName: 'John',
            lastName: 'Doe',
            country: 'PL',
            city: 'Warszawa',
            address1: 'Prosta 1',
            taxId: '5252445797',
        );

        self::assertSame([
            'email' => 'john.doe@example.com',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'country' => 'PL',
            'city' => 'Warszawa',
            'address1' => 'Prosta 1',
            'taxId' => '5252445797',
        ], $payload->toArray());
    }

    public function testCreateRmaRequestItemSerialisesOnlyWhatWasSet(): void
    {
        $payload = new CreateRmaRequestItem(
            orderId: 'ORDER-123',
            productId: 'SKU-9',
            quantity: 2,
            reasonId: 3,
            conditionId: 4,
            resolutionId: 5,
        );

        self::assertSame([
            'orderId' => 'ORDER-123',
            'productId' => 'SKU-9',
            'quantity' => 2,
            'reasonId' => 3,
            'conditionId' => 4,
            'resolutionId' => 5,
        ], $payload->toArray());
    }

    public function testUpdateProductSerialisesOnlyWhatWasSet(): void
    {
        self::assertSame(
            ['confirmedQty' => 1, 'confirmedAmount' => 49.99, 'confirmedCurrency' => 'PLN'],
            (new UpdateProduct(1, 49.99, 'PLN'))->toArray(),
        );
        self::assertSame(['confirmedQty' => 0], (new UpdateProduct(confirmedQty: 0))->toArray());
    }

    /**
     * Zero and the empty string are values a caller can legitimately mean; only null counts
     * as "not set".
     */
    public function testFalsyValuesSurviveSerialisation(): void
    {
        $payload = new UpdateProduct(confirmedQty: 0, confirmedAmount: 0.0, confirmedCurrency: '');

        self::assertSame(
            ['confirmedQty' => 0, 'confirmedAmount' => 0.0, 'confirmedCurrency' => ''],
            $payload->toArray(),
        );
    }

    public function testEveryPayloadSharesTheContract(): void
    {
        self::assertInstanceOf(Payload::class, new CreateRmaRequest(
            saleChannelId: 7,
            customer: $this->customer(),
            items: [$this->item()],
        ));
        self::assertInstanceOf(Payload::class, $this->customer());
        self::assertInstanceOf(Payload::class, $this->item());
        self::assertInstanceOf(Payload::class, new UpdateProduct());
    }
}
