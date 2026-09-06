<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Integration\Resource;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RetJetApi\Returns\Client;
use RetJetApi\Returns\Model\RmaRequestFollower;
use RetJetApi\Returns\Model\TimelineEntry;
use RetJetApi\Returns\Request\CreateRmaRequest;
use RetJetApi\Returns\Request\CreateRmaRequestCustomer;
use RetJetApi\Returns\Request\CreateRmaRequestItem;
use RetJetApi\Returns\Request\UpdateProduct;
use RetJetApi\Returns\Resource\RmaRequests;
use RetJetApi\Returns\Tests\Support\Fixtures;
use RetJetApi\Returns\Tests\Support\MockHttpClient;

/**
 * Every RmaRequests operation over a stubbed PSR-18 client: the path it targets, the body it
 * sends and what it gives back.
 *
 * Paths are asserted as literals because this resource has the most of them: the item and
 * fifteen single-request actions, all under one plural segment that has been renamed once
 * already, and the spec misnames the path parameter of three of them.
 */
#[CoversClass(RmaRequests::class)]
final class RmaRequestsTest extends TestCase
{
    private MockHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
    }

    private function rmaRequests(): RmaRequests
    {
        return Client::builder()
            ->withApiKey('secret-key')
            ->withBaseUri('https://api.example.com')
            ->withHttpClient($this->http)
            ->build()
            ->rmaRequests();
    }

    private function lastRequest(): RequestInterface
    {
        return $this->http->lastRequest();
    }

    private function assertSentTo(string $method, string $uri): void
    {
        $request = $this->lastRequest();

        self::assertSame($method, $request->getMethod());
        self::assertSame($uri, (string) $request->getUri());
        self::assertSame('Bearer secret-key', $request->getHeaderLine('Authorization'));
    }

    /**
     * @return array<string, mixed>
     */
    private function sentBody(): array
    {
        $decoded = json_decode((string) $this->lastRequest()->getBody(), true);

        self::assertIsArray($decoded);

        $body = [];

        foreach ($decoded as $key => $value) {
            $body[(string) $key] = $value;
        }

        return $body;
    }

    // ------------------------------------------------------------------- the resource

    public function testList(): void
    {
        $this->http->willRespondWithJson(200, Fixtures::load('rma_requests_page_1'));

        $page = $this->rmaRequests()->list();

        $this->assertSentTo('GET', 'https://api.example.com/v1/rma-requests?page=1');
        self::assertCount(2, $page);
        self::assertSame(5, $page->totalItems());
        self::assertSame('RMA-2024-001234', $page->member()[0]->identifier);
    }

    public function testListPage(): void
    {
        $this->http->willRespondWithJson(200, Fixtures::load('rma_requests_page_2'));

        $this->rmaRequests()->list(page: 2);

        $this->assertSentTo('GET', 'https://api.example.com/v1/rma-requests?page=2');
    }

    public function testIterateWalksEveryPage(): void
    {
        $this->http
            ->willRespondWithJson(200, Fixtures::load('rma_requests_page_1'))
            ->willRespondWithJson(200, Fixtures::load('rma_requests_page_2'))
            ->willRespondWithJson(200, Fixtures::load('rma_requests_page_3'));

        $ids = [];

        foreach ($this->rmaRequests()->iterate() as $rma) {
            $ids[] = $rma->id;
        }

        self::assertSame([1234, 1235, 1236, 1237, 1238], $ids);
        self::assertSame(3, $this->http->requestCount());
    }

    /**
     * The item hangs off the collection path. Asserted separately from the sweep below so a
     * failure here points at the item and not at one of the actions.
     */
    public function testGetUsesThePluralItemPath(): void
    {
        $this->http->willRespondWithJson(200, Fixtures::load('rma_request'));

        $rma = $this->rmaRequests()->get(1234);

        $this->assertSentTo('GET', 'https://api.example.com/v1/rma-requests/1234');
        self::assertSame(1234, $rma->id);
        self::assertNotNull($rma->customer);
        self::assertSame('john.doe@example.com', $rma->customer->email);
    }

    public function testCreateSendsThePayloadAndReturnsTheCreatedRequest(): void
    {
        $this->http->willRespondWithJson(201, Fixtures::load('rma_request'));

        $rma = $this->rmaRequests()->create(new CreateRmaRequest(
            saleChannelId: 7,
            customer: new CreateRmaRequestCustomer(
                email: 'john.doe@example.com',
                firstName: 'John',
                lastName: 'Doe',
                country: 'PL',
                city: 'Warszawa',
                address1: 'Prosta 1',
            ),
            items: [new CreateRmaRequestItem(
                orderId: 'ORDER-123',
                productId: 'SKU-9',
                quantity: 1,
                reasonId: 3,
                conditionId: 4,
            )],
            type: 'return',
            customerInfo: 'Damaged on arrival',
        ));

        $this->assertSentTo('POST', 'https://api.example.com/v1/rma-requests');
        self::assertSame('application/json', $this->lastRequest()->getHeaderLine('Content-Type'));
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
                'quantity' => 1,
                'reasonId' => 3,
                'conditionId' => 4,
            ]],
            'type' => 'return',
            'customerInfo' => 'Damaged on arrival',
        ], $this->sentBody(), 'Unset members are omitted, not sent as null.');

        self::assertSame(1234, $rma->id, 'The server assigns the id, so create() returns it.');
        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $rma->uuid);
    }

    // ------------------------------------------------------------------- status

    public function testChangeStatus(): void
    {
        $this->http->willRespondWithJson(201, ['requestId' => 1234, 'stateIdentifier' => 'in_progress']);

        $this->rmaRequests()->changeStatus(1234, 'in_progress');

        $this->assertSentTo('POST', 'https://api.example.com/v1/rma-requests/1234/status');
        self::assertSame(['requestId' => 1234, 'stateIdentifier' => 'in_progress'], $this->sentBody());
    }

    /**
     * The endpoint echoes the payload back rather than returning the updated request, so the
     * method returns nothing and the caller has to re-read to see the new state.
     */
    public function testAnActionReturnsNothingAndTheNewStateNeedsAFreshGet(): void
    {
        $updated = Fixtures::load('rma_request');
        $updated['state'] = ['label' => 'in_progress', 'labelTranslated' => 'In Progress'];

        $this->http
            ->willRespondWithJson(201, ['requestId' => 1234, 'stateIdentifier' => 'in_progress'])
            ->willRespondWithJson(200, $updated);

        $resource = $this->rmaRequests();

        $resource->changeStatus(1234, 'in_progress');

        // Everything the action endpoint answered with is an echo of what was just sent, so
        // there is nothing for the method to hand back.
        self::assertSame(['requestId' => 1234, 'stateIdentifier' => 'in_progress'], $this->sentBody());

        $rma = $resource->get(1234);

        self::assertNotNull($rma->state);
        self::assertSame('in_progress', $rma->state->label);
        self::assertSame(2, $this->http->requestCount());
    }

    // ------------------------------------------------------------------- owner

    public function testAssignOwner(): void
    {
        $this->http->willRespondWithJson(201, ['requestId' => 1234, 'userId' => 42]);

        $this->rmaRequests()->assignOwner(1234, 42);

        $this->assertSentTo('POST', 'https://api.example.com/v1/rma-requests/1234/owner');
        self::assertSame(['requestId' => 1234, 'userId' => 42], $this->sentBody());
    }

    public function testUnassignOwner(): void
    {
        $this->http->willRespond(204);

        $this->rmaRequests()->unassignOwner(1234);

        $this->assertSentTo('DELETE', 'https://api.example.com/v1/rma-requests/1234/owner');
        self::assertSame('', (string) $this->lastRequest()->getBody(), 'A documented bodyless DELETE stays bodyless.');
    }

    // ------------------------------------------------------------------- followers

    /**
     * RmaRequestFollower is the one action schema with no member for the RMA request: the
     * spec describes its `id` as "User ID of the follower". Sending the request id there
     * would follow whichever user happens to share that number.
     */
    public function testAddFollowerNeverSendsTheRequestIdInTheBody(): void
    {
        $this->http->willRespondWithJson(201, ['id' => 42, 'userId' => 42]);

        $this->rmaRequests()->addFollower(1234, 42);

        $this->assertSentTo('POST', 'https://api.example.com/v1/rma-requests/1234/follower');
        self::assertSame(['userId' => 42], $this->sentBody());
        self::assertArrayNotHasKey('id', $this->sentBody(), 'The request id belongs to the path only.');
    }

    public function testAddFollowerDefaultsToTheAuthenticatedUser(): void
    {
        $this->http->willRespondWithJson(201, []);

        $this->rmaRequests()->addFollower(1234);

        self::assertSame([], $this->sentBody());
        self::assertSame('{}', (string) $this->lastRequest()->getBody(), 'An empty payload is a JSON object.');
    }

    public function testRemoveFollowerSendsNoBodyByDefault(): void
    {
        $this->http->willRespond(204);

        $this->rmaRequests()->removeFollower(1234);

        $this->assertSentTo('DELETE', 'https://api.example.com/v1/rma-requests/1234/follower');
        self::assertSame('', (string) $this->lastRequest()->getBody());
    }

    /**
     * followers() and timeline() return one page. Without these, a watcher list or a history
     * longer than the server page size is silently truncated while totalItems() reports the
     * real count. The paginator follows view.next, so it works whether or not the endpoint
     * accepts a `page` parameter - which the spec does not declare for either path.
     */
    public function testIterateFollowersWalksEveryPage(): void
    {
        $follower = Fixtures::load('rma_request_follower');

        $this->http
            ->willRespondWithJson(200, [
                'member' => [$follower],
                'totalItems' => 2,
                'view' => ['next' => 'https://api.example.com/v1/rma-requests/1234/followers?page=2'],
            ])
            ->willRespondWithJson(200, ['member' => [$follower], 'totalItems' => 2]);

        $all = iterator_to_array($this->rmaRequests()->iterateFollowers(1234), false);

        self::assertCount(2, $all);
        self::assertContainsOnlyInstancesOf(RmaRequestFollower::class, $all);
        self::assertSame(2, $this->http->requestCount());
    }

    public function testIterateTimelineWalksEveryPageAndIsLazy(): void
    {
        $entry = ['id' => 1, 'type' => 'status_change', 'createdAt' => 1709913600];

        $this->http
            ->willRespondWithJson(200, [
                'member' => [$entry],
                'totalItems' => 2,
                'view' => ['next' => 'https://api.example.com/v1/rma-requests/1234/timeline?page=2'],
            ])
            ->willRespondWithJson(200, ['member' => [$entry], 'totalItems' => 2]);

        $entries = $this->rmaRequests()->iterateTimeline(1234);

        self::assertSame(0, $this->http->requestCount(), 'Building the generator sends nothing.');
        self::assertCount(2, iterator_to_array($entries, false));
        self::assertSame(2, $this->http->requestCount());
    }

    public function testFollowersReturnsACollectionOfFollowers(): void
    {
        $this->http->willRespondWithJson(200, Fixtures::hydraCollection(
            [Fixtures::load('rma_request_follower')],
            1,
        ));

        $followers = $this->rmaRequests()->followers(1234);

        $this->assertSentTo('GET', 'https://api.example.com/v1/rma-requests/1234/followers');
        self::assertCount(1, $followers);
        self::assertContainsOnlyInstancesOf(RmaRequestFollower::class, $followers->member());
        self::assertSame('agent@company.com', $followers->member()[0]->email);
        self::assertSame(42, $followers->member()[0]->userId);
    }

    // ------------------------------------------------------------------- annotations

    public function testAddMessage(): void
    {
        $this->http->willRespondWithJson(201, []);

        $this->rmaRequests()->addMessage(1234, 'We have received your return.', public: true);

        $this->assertSentTo('POST', 'https://api.example.com/v1/rma-requests/1234/message');
        self::assertSame([
            'requestId' => 1234,
            'message' => 'We have received your return.',
            'public' => true,
        ], $this->sentBody());
    }

    public function testAddMessageDefaultsToAnInternalNote(): void
    {
        $this->http->willRespondWithJson(201, []);

        $this->rmaRequests()->addMessage(1234, 'Internal note');

        self::assertFalse($this->sentBody()['public']);
    }

    public function testAddStamp(): void
    {
        $this->http->willRespondWithJson(201, []);

        $this->rmaRequests()->addStamp(1234, 'priority');

        $this->assertSentTo('POST', 'https://api.example.com/v1/rma-requests/1234/stamp');
        self::assertSame(['requestId' => 1234, 'stampId' => 'priority'], $this->sentBody());
    }

    /**
     * The star payload carries an explicit `starred` boolean; unstar is the DELETE on the
     * very same path.
     */
    public function testStar(): void
    {
        $this->http->willRespondWithJson(201, []);

        $this->rmaRequests()->star(1234);

        $this->assertSentTo('POST', 'https://api.example.com/v1/rma-requests/1234/star');
        self::assertSame(['requestId' => 1234, 'starred' => true], $this->sentBody());
    }

    public function testUnstar(): void
    {
        $this->http->willRespond(204);

        $this->rmaRequests()->unstar(1234);

        $this->assertSentTo('DELETE', 'https://api.example.com/v1/rma-requests/1234/star');
        self::assertSame('', (string) $this->lastRequest()->getBody());
    }

    // ------------------------------------------------------------------- settlement

    public function testSetDeadlineFromAString(): void
    {
        $this->http->willRespondWithJson(201, []);

        $this->rmaRequests()->setDeadline(1234, '2024-12-31');

        $this->assertSentTo('POST', 'https://api.example.com/v1/rma-requests/1234/deadline');
        self::assertSame(['requestId' => 1234, 'deadline' => '2024-12-31'], $this->sentBody());
    }

    public function testSetDeadlineFromADateTime(): void
    {
        $this->http->willRespondWithJson(201, []);

        $this->rmaRequests()->setDeadline(1234, new DateTimeImmutable('2024-12-31 18:45:00'));

        self::assertSame('2024-12-31', $this->sentBody()['deadline'], 'Formatted as the spec example shows.');
    }

    public function testSetApprovedAmount(): void
    {
        $this->http->willRespondWithJson(201, []);

        $this->rmaRequests()->setApprovedAmount(1234, 99.99, 'PLN');

        $this->assertSentTo('POST', 'https://api.example.com/v1/rma-requests/1234/approved-amount');
        self::assertSame(['requestId' => 1234, 'amount' => 99.99, 'currency' => 'PLN'], $this->sentBody());
    }

    /**
     * JSON with a `files` array of strings, not multipart.
     */
    public function testAddAttachments(): void
    {
        $this->http->willRespondWithJson(201, []);

        $this->rmaRequests()->addAttachments(1234, ['https://cdn.example.com/a.jpg', 'https://cdn.example.com/b.jpg']);

        $this->assertSentTo('POST', 'https://api.example.com/v1/rma-requests/1234/attachments');
        self::assertSame('application/json', $this->lastRequest()->getHeaderLine('Content-Type'));
        self::assertSame([
            'requestId' => 1234,
            'files' => ['https://cdn.example.com/a.jpg', 'https://cdn.example.com/b.jpg'],
        ], $this->sentBody());
    }

    public function testUpdateProduct(): void
    {
        $this->http->willRespondWithJson(200, []);

        $this->rmaRequests()->updateProduct(1234, 42, new UpdateProduct(
            confirmedQty: 1,
            confirmedAmount: 49.99,
            confirmedCurrency: 'PLN',
        ));

        $this->assertSentTo('PUT', 'https://api.example.com/v1/rma-requests/1234/product/42');
        self::assertSame([
            'requestId' => 1234,
            'productId' => 42,
            'confirmedQty' => 1,
            'confirmedAmount' => 49.99,
            'confirmedCurrency' => 'PLN',
        ], $this->sentBody());
    }

    public function testUpdateProductOmitsMembersLeftUnset(): void
    {
        $this->http->willRespondWithJson(200, []);

        $this->rmaRequests()->updateProduct(1234, 42, new UpdateProduct(confirmedQty: 2));

        self::assertSame(['requestId' => 1234, 'productId' => 42, 'confirmedQty' => 2], $this->sentBody());
    }

    // ------------------------------------------------------------------- history

    public function testTimelineReturnsACollectionOfEntries(): void
    {
        $this->http->willRespondWithJson(200, Fixtures::hydraCollection(
            [Fixtures::load('timeline_entry')],
            1,
        ));

        $timeline = $this->rmaRequests()->timeline(1234);

        $this->assertSentTo('GET', 'https://api.example.com/v1/rma-requests/1234/timeline');
        self::assertCount(1, $timeline);
        self::assertContainsOnlyInstancesOf(TimelineEntry::class, $timeline->member());

        $entry = $timeline->member()[0];

        self::assertSame('status_change', $entry->type);
        self::assertSame(1709913600, $entry->createdAt);
        self::assertTrue($entry->public);
        self::assertArrayHasKey('user', $entry->raw(), 'user and data stay untyped in raw().');
    }

    // ------------------------------------------------------------------- paths

    /**
     * Guards every single-request path in one place: whatever else changes, the item and all
     * fifteen actions must stay under `/v1/rma-requests`.
     */
    public function testTheItemAndEveryActionShareThePluralSegment(): void
    {
        $this->http
            ->willRespondWithJson(200, Fixtures::load('rma_request'))
            ->willRespondWithJson(201, [])
            ->willRespondWithJson(201, [])
            ->willRespond(204)
            ->willRespondWithJson(201, [])
            ->willRespond(204)
            ->willRespondWithJson(200, Fixtures::hydraCollection([]))
            ->willRespondWithJson(201, [])
            ->willRespondWithJson(201, [])
            ->willRespondWithJson(201, [])
            ->willRespond(204)
            ->willRespondWithJson(201, [])
            ->willRespondWithJson(201, [])
            ->willRespondWithJson(201, [])
            ->willRespondWithJson(200, [])
            ->willRespondWithJson(200, Fixtures::hydraCollection([]));

        $resource = $this->rmaRequests();

        $resource->get(1234);
        $resource->changeStatus(1234, 'new');
        $resource->assignOwner(1234, 42);
        $resource->unassignOwner(1234);
        $resource->addFollower(1234, 42);
        $resource->removeFollower(1234);
        $resource->followers(1234);
        $resource->addMessage(1234, 'hi');
        $resource->addStamp(1234, 'priority');
        $resource->star(1234);
        $resource->unstar(1234);
        $resource->setDeadline(1234, '2024-12-31');
        $resource->setApprovedAmount(1234, 1.0, 'PLN');
        $resource->addAttachments(1234, []);
        $resource->updateProduct(1234, 42, new UpdateProduct());
        $resource->timeline(1234);

        $paths = array_map(
            static fn (RequestInterface $r): string => $r->getUri()->getPath(),
            $this->http->requests(),
        );

        self::assertSame([
            '/v1/rma-requests/1234',
            '/v1/rma-requests/1234/status',
            '/v1/rma-requests/1234/owner',
            '/v1/rma-requests/1234/owner',
            '/v1/rma-requests/1234/follower',
            '/v1/rma-requests/1234/follower',
            '/v1/rma-requests/1234/followers',
            '/v1/rma-requests/1234/message',
            '/v1/rma-requests/1234/stamp',
            '/v1/rma-requests/1234/star',
            '/v1/rma-requests/1234/star',
            '/v1/rma-requests/1234/deadline',
            '/v1/rma-requests/1234/approved-amount',
            '/v1/rma-requests/1234/attachments',
            '/v1/rma-requests/1234/product/42',
            '/v1/rma-requests/1234/timeline',
        ], $paths);
    }
}
