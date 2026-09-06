<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Tests\Integration\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use ReflectionNamedType;
use RetJetApi\Returns\Client;
use RetJetApi\Returns\Exception\ConfigurationException;
use RetJetApi\Returns\Resource\RmaRequests;
use RetJetApi\Returns\Tests\Support\MockHttpClient;

/**
 * The five bulk operations over a stubbed PSR-18 client.
 *
 * Two things are asserted harder here than anywhere else, because both are silent failures
 * against a live API rather than errors a test would trip over by accident:
 *
 * 1. Bulk paths sit under `/v1/rma-requests/bulk/...`, next to but not inside the
 *    single-request actions they resemble (`/v1/rma-requests/{id}/...`).
 * 2. `requestIds` is serialised as JSON **numbers**. The spec declares `items: {type: string}`
 *    but every example is `[1, 2, 3]`, so a string-typed reading would put `["1","2","3"]` on
 *    the wire - which the assertions below would catch and `assertEquals` would not.
 */
#[CoversClass(RmaRequests::class)]
final class RmaRequestsBulkTest extends TestCase
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

    private function sentJson(): string
    {
        return (string) $this->lastRequest()->getBody();
    }

    /**
     * @return array<string, mixed>
     */
    private function sentBody(): array
    {
        $decoded = json_decode($this->sentJson(), true);

        self::assertIsArray($decoded);

        $body = [];

        foreach ($decoded as $key => $value) {
            $body[(string) $key] = $value;
        }

        return $body;
    }

    // ------------------------------------------------------------------- owner

    public function testBulkAssignOwner(): void
    {
        $this->http->willRespondWithJson(201, ['requestIds' => [1, 2, 3], 'userId' => 42]);

        $this->rmaRequests()->bulkAssignOwner([1, 2, 3], 42);

        $this->assertSentTo('POST', 'https://api.example.com/v1/rma-requests/bulk/owner');
        self::assertSame(['requestIds' => [1, 2, 3], 'userId' => 42], $this->sentBody());
    }

    /**
     * BulkOwner is shared with the assign operation and declares a `userId`, but the spec
     * describes it as "Required for assign, ignored for unassign" - so it is not sent.
     */
    public function testBulkUnassignOwnerSendsNoUserId(): void
    {
        $this->http->willRespondWithJson(201, ['requestIds' => [1, 2, 3]]);

        $this->rmaRequests()->bulkUnassignOwner([1, 2, 3]);

        $this->assertSentTo('POST', 'https://api.example.com/v1/rma-requests/bulk/unassign-owner');
        self::assertSame(['requestIds' => [1, 2, 3]], $this->sentBody());
        self::assertArrayNotHasKey('userId', $this->sentBody());
    }

    // ------------------------------------------------------------------- star

    /**
     * The single-request star() sends `{starred: true}`; BulkStar declares nothing but
     * `requestIds`, so the bulk payload carries the intent in the path alone.
     */
    public function testBulkStarSendsOnlyRequestIds(): void
    {
        $this->http->willRespondWithJson(201, ['requestIds' => [1, 2, 3]]);

        $this->rmaRequests()->bulkStar([1, 2, 3]);

        $this->assertSentTo('POST', 'https://api.example.com/v1/rma-requests/bulk/star');
        self::assertSame(['requestIds' => [1, 2, 3]], $this->sentBody());
    }

    /**
     * A POST, where the single-request unstar() is a DELETE.
     */
    public function testBulkUnstarIsAPostToItsOwnPath(): void
    {
        $this->http->willRespondWithJson(201, ['requestIds' => [1, 2, 3]]);

        $this->rmaRequests()->bulkUnstar([1, 2, 3]);

        $this->assertSentTo('POST', 'https://api.example.com/v1/rma-requests/bulk/unstar');
        self::assertSame(['requestIds' => [1, 2, 3]], $this->sentBody());
    }

    // ------------------------------------------------------------------- status

    /**
     * Bulk uses `statusId`; the single-request changeStatus() uses `stateIdentifier`. The
     * spec genuinely disagrees with itself here and the SDK follows each endpoint as written.
     */
    public function testBulkChangeStatusUsesStatusIdNotStateIdentifier(): void
    {
        $this->http->willRespondWithJson(201, ['requestIds' => [1, 2, 3], 'statusId' => 'approved']);

        $this->rmaRequests()->bulkChangeStatus([1, 2, 3], 'approved');

        $this->assertSentTo('POST', 'https://api.example.com/v1/rma-requests/bulk/status');
        self::assertSame(['requestIds' => [1, 2, 3], 'statusId' => 'approved'], $this->sentBody());
        self::assertArrayNotHasKey('stateIdentifier', $this->sentBody());
    }

    // ------------------------------------------------------------------- requestIds serialisation

    /**
     * The heart of it: the JSON text itself, not a decoded structure. `[1,2,3]` and
     * `["1","2","3"]` decode to values that assertEquals cannot tell apart, so the raw body
     * is what gets asserted.
     */
    public function testRequestIdsAreSerialisedAsJsonNumbers(): void
    {
        $this->http->willRespondWithJson(201, []);

        $this->rmaRequests()->bulkStar([1, 2, 3]);

        self::assertSame('{"requestIds":[1,2,3]}', $this->sentJson());
    }

    /**
     * Calls something $requestIds' declared `list<int>` forbids, so it goes through reflection
     * rather than a direct call - not to dodge the analyser, but because these model the only
     * callers for whom the normalisation exists: the ones PHP never type-checked. Ids arriving
     * from $_POST, json_decode() or a database driver are strings, and a list that has been
     * through array_filter() is not a list. Both are ordinary calling code and both would put
     * an unusable payload on the wire.
     *
     * @param array<array-key, mixed> $ids
     */
    private function bulkStarWithUncheckedIds(array $ids): void
    {
        $resource = $this->rmaRequests();

        (new ReflectionMethod($resource, 'bulkStar'))->invoke($resource, $ids);
    }

    /**
     * A caller reading the spec's declared `items: {type: string}` hands over strings; they
     * still go out as numbers.
     */
    public function testStringIdsAreCoercedToNumbers(): void
    {
        $this->http->willRespondWithJson(201, []);

        $this->bulkStarWithUncheckedIds(['1', '2', '3']);

        self::assertSame('{"requestIds":[1,2,3]}', $this->sentJson());
    }

    /**
     * `requestIds` must be a JSON array, never an object. An id list that came out of
     * array_filter() keeps its original keys, and json_encode would emit `{"1":2}` for it -
     * a payload the endpoint cannot read, produced by entirely reasonable calling code.
     */
    public function testAFilteredIdListStillSerialisesAsAJsonArray(): void
    {
        $this->http->willRespondWithJson(201, []);

        $filtered = array_filter([1, 2, 3], static fn (int $id): bool => $id !== 1);

        self::assertSame([1 => 2, 2 => 3], $filtered, 'array_filter preserves keys - the trap.');

        $this->bulkStarWithUncheckedIds($filtered);

        self::assertSame('{"requestIds":[2,3]}', $this->sentJson());
    }

    public function testAnEmptyIdListStillSerialisesAsAJsonArray(): void
    {
        $this->http->willRespondWithJson(201, []);

        $this->rmaRequests()->bulkStar([]);

        self::assertSame('{"requestIds":[]}', $this->sentJson());
    }

    // ------------------------------------------------------------------- paths

    /**
     * Every bulk path in one assertion, mirroring
     * testTheItemAndEveryActionShareThePluralSegment in RmaRequestsTest. Bulk hangs off
     * `/bulk/`, so no bulk call may ever be built through the action() helper.
     */
    public function testEveryBulkPathIsPlural(): void
    {
        $this->http
            ->willRespondWithJson(201, [])
            ->willRespondWithJson(201, [])
            ->willRespondWithJson(201, [])
            ->willRespondWithJson(201, [])
            ->willRespondWithJson(201, []);

        $resource = $this->rmaRequests();

        $resource->bulkAssignOwner([1], 42);
        $resource->bulkUnassignOwner([1]);
        $resource->bulkStar([1]);
        $resource->bulkUnstar([1]);
        $resource->bulkChangeStatus([1], 'approved');

        $paths = array_map(
            static fn (RequestInterface $r): string => $r->getUri()->getPath(),
            $this->http->requests(),
        );

        self::assertSame([
            '/v1/rma-requests/bulk/owner',
            '/v1/rma-requests/bulk/unassign-owner',
            '/v1/rma-requests/bulk/star',
            '/v1/rma-requests/bulk/unstar',
            '/v1/rma-requests/bulk/status',
        ], $paths);

        foreach ($this->http->requests() as $request) {
            self::assertSame('POST', $request->getMethod());
            self::assertStringStartsWith('/v1/rma-requests/bulk/', $request->getUri()->getPath());
        }
    }

    /**
     * The 201 body is a pure echo of the payload - no server-assigned members anywhere in the
     * Bulk* schemas - so there is nothing for these methods to hand back, and none of them
     * reports which ids the operation actually applied to.
     */
    public function testEveryBulkOperationReturnsVoid(): void
    {
        foreach ([
            'bulkAssignOwner',
            'bulkUnassignOwner',
            'bulkStar',
            'bulkUnstar',
            'bulkChangeStatus',
        ] as $name) {
            $returnType = (new ReflectionMethod(RmaRequests::class, $name))->getReturnType();

            self::assertInstanceOf(ReflectionNamedType::class, $returnType);
            self::assertSame('void', $returnType->getName(), $name . '() must return void.');
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedIds(): iterable
    {
        yield 'trailing letters' => ['12a'];
        yield 'not a number' => ['abc'];
        yield 'empty string' => [''];
        yield 'float' => [7.9];
        yield 'null' => [null];
        yield 'array' => [[1]];
    }

    /**
     * intval('12a') is 12 and intval('abc') is 0, so coercing would fire a bulk operation at a
     * real, unrelated RMA request. These methods return void and the API reports no per-request
     * outcome, so the caller would never learn about it.
     *
     * Called through reflection for the same reason as the tests above: PHP never type-checks
     * the callers this normalisation exists for ($_POST, json_decode, a database driver).
     */
    #[DataProvider('malformedIds')]
    public function testAMalformedIdIsRejectedInsteadOfCoerced(mixed $id): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must be an integer RMA request id');

        try {
            $this->bulkStarWithUncheckedIds([1, $id, 3]);
        } finally {
            self::assertSame(0, $this->http->requestCount(), 'Nothing may reach the network.');
        }
    }
}
