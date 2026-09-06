<?php

declare(strict_types=1);

namespace RetJetApi\Returns\Resource;

use DateTimeInterface;
use Generator;
use RetJetApi\Returns\Collection\Paginator;
use RetJetApi\Returns\Collection\ResourceCollection;
use RetJetApi\Returns\Exception\ConfigurationException;
use RetJetApi\Returns\Model\RmaRequest;
use RetJetApi\Returns\Model\RmaRequestFollower;
use RetJetApi\Returns\Model\TimelineEntry;
use RetJetApi\Returns\Request\CreateRmaRequest;
use RetJetApi\Returns\Request\UpdateProduct;

/**
 * Return / RMA requests: the collection, one request, and every action that can be taken on
 * one. Actions are flat methods taking $requestId first - there is no intermediate
 * actions($id) object, so `$client->rmaRequests()->` lists everything the resource can do.
 *
 * ## Actions return void
 *
 * Every action endpoint answers with an echo of the payload just sent (`RmaRequestStatusChange`
 * is exactly `{requestId, stateIdentifier}`) or with 204. None of them returns the updated
 * request, so **after an action you need a fresh get() to see the new state**:
 *
 *     $rma->changeStatus(1234, 'in_progress');
 *     $updated = $rma->get(1234);        // the only way to observe the new state
 *
 * Success is signalled by the absence of an exception. Should the API ever start returning
 * something worth having, widening a void method to return a value is not a breaking change.
 *
 * The bulk* methods return void for the same reason, but their silence is worth more caution:
 * their response echoes the payload too, so it carries **no per-request outcome**. A bulk call
 * that returns cleanly does not prove every id in it was accepted, and there is nothing in the
 * response to tell a caller which one was not. Re-read the requests that matter.
 *
 * ## Paths
 *
 * Taken verbatim from `paths`. The collection, the item (`/v1/rma-requests/{id}`) and every
 * action (`/v1/rma-requests/{requestId}/...`) share one plural segment.
 *
 * Bulk operations sit under that same segment: `/v1/rma-requests/bulk/...`. They take an id
 * list rather than a single id, so the action() helper must not be used to build them.
 *
 * @extends AbstractResource<RmaRequest>
 */
final class RmaRequests extends AbstractResource
{
    // ----------------------------------------------------------------- the resource itself

    /**
     * One page: GET /v1/rma-requests
     *
     * @param array<string, scalar|null> $query extra query parameters, merged with `page`
     *
     * @return ResourceCollection<RmaRequest>
     */
    public function list(int $page = 1, array $query = []): ResourceCollection
    {
        return $this->collection($page, $query);
    }

    /**
     * Every request across every page, fetched one page at a time as the iteration advances.
     *
     * @param array<string, scalar|null> $query
     *
     * @return Generator<int, RmaRequest>
     */
    public function iterate(array $query = []): Generator
    {
        return $this->paginate($query)->getIterator();
    }

    /**
     * One request by id: GET /v1/rma-requests/{id}.
     */
    public function get(int $id): RmaRequest
    {
        return $this->item($id);
    }

    /**
     * POST /v1/rma-requests
     *
     * The one write that returns real data: the created request, carrying the id, uuid and
     * createdAt the server assigned.
     */
    public function create(CreateRmaRequest $request): RmaRequest
    {
        return $this->hydrate($this->post('/v1/rma-requests', $request->toArray()));
    }

    // ----------------------------------------------------------------- status

    /**
     * POST /v1/rma-requests/{requestId}/status - moves the request to another workflow state.
     *
     * Returns nothing; call get() afterwards to observe the new state.
     */
    public function changeStatus(int $requestId, string $stateIdentifier): void
    {
        $this->post($this->action($requestId, 'status'), $this->identify($requestId, [
            'stateIdentifier' => $stateIdentifier,
        ]));
    }

    // ----------------------------------------------------------------- owner

    /**
     * POST /v1/rma-requests/{requestId}/owner - assigns the agent responsible for the request.
     *
     * Returns nothing; call get() afterwards to observe the new owner.
     */
    public function assignOwner(int $requestId, int $userId): void
    {
        $this->post($this->action($requestId, 'owner'), $this->identify($requestId, ['userId' => $userId]));
    }

    /**
     * DELETE /v1/rma-requests/{requestId}/owner - leaves the request unassigned.
     */
    public function unassignOwner(int $requestId): void
    {
        $this->delete($this->action($requestId, 'owner'));
    }

    // ----------------------------------------------------------------- followers

    /**
     * POST /v1/rma-requests/{requestId}/follower - adds someone to the request's watchers.
     *
     * Unlike every other action schema, RmaRequestFollower carries no member for the RMA
     * request: the spec describes its `id` as "User ID of the follower", so the request is
     * identified by the path alone. Sending the request id in the body would name the wrong
     * user, which is why nothing but $userId goes out.
     *
     * Omitting $userId follows the authenticated user - the behaviour the spec documents for
     * an empty body.
     */
    public function addFollower(int $requestId, ?int $userId = null): void
    {
        $this->post(
            $this->action($requestId, 'follower'),
            $userId === null ? [] : ['userId' => $userId],
        );
    }

    /**
     * DELETE /v1/rma-requests/{requestId}/follower - stops following the request.
     *
     * The spec declares no request body and no query parameter here, so the only expressible
     * operation is unfollowing the authenticated user; there is no way to name another one.
     * The field description on the corresponding write says "User ID to follow/unfollow", but
     * this operation offers nowhere to put it: a server that ignores an undeclared body would
     * drop the *caller's* own follow and still answer 204, which is why no $userId parameter
     * is offered here to begin with rather than one that could only ever fail.
     */
    public function removeFollower(int $requestId): void
    {
        $this->delete($this->action($requestId, 'follower'));
    }

    /**
     * GET /v1/rma-requests/{requestId}/followers - the first page of the request's watchers.
     *
     * The spec declares no `page` parameter here, so none is sent. Use iterateFollowers()
     * when the list may be longer than one page: totalItems() reports the true count, so a
     * caller reading only this collection can silently miss watchers.
     *
     * @return ResourceCollection<RmaRequestFollower>
     */
    public function followers(int $requestId): ResourceCollection
    {
        return ResourceCollection::fromPayload(
            $this->fetch($this->action($requestId, 'followers')),
            RmaRequestFollower::class,
        );
    }

    /**
     * Every watcher, lazily, by following Hydra's view.next.
     *
     * This works whether or not the endpoint takes a `page` parameter, because the paginator
     * follows the links the response carries rather than constructing page URLs itself. On an
     * unpaginated response it simply yields the single page and stops.
     *
     * @return Generator<int, RmaRequestFollower>
     */
    public function iterateFollowers(int $requestId): Generator
    {
        yield from new Paginator(
            $this->transport(),
            $this->action($requestId, 'followers'),
            RmaRequestFollower::class,
        );
    }

    // ----------------------------------------------------------------- annotations

    /**
     * POST /v1/rma-requests/{requestId}/message - adds a message to the request.
     *
     * $public decides whether the customer sees it or whether it stays an internal note.
     */
    public function addMessage(int $requestId, string $message, bool $public = false): void
    {
        $this->post($this->action($requestId, 'message'), $this->identify($requestId, [
            'message' => $message,
            'public' => $public,
        ]));
    }

    /**
     * POST /v1/rma-requests/{requestId}/stamp - attaches a stamp (label) to the request.
     */
    public function addStamp(int $requestId, string $stampId): void
    {
        $this->post($this->action($requestId, 'stamp'), $this->identify($requestId, ['stampId' => $stampId]));
    }

    /**
     * POST /v1/rma-requests/{requestId}/star - flags the request for attention.
     *
     * The payload schema carries a `starred` boolean, so `true` is sent explicitly rather
     * than relying on the endpoint inferring it from the verb; unstar() is the DELETE on the
     * same path.
     */
    public function star(int $requestId): void
    {
        $this->post($this->action($requestId, 'star'), $this->identify($requestId, ['starred' => true]));
    }

    /**
     * DELETE /v1/rma-requests/{requestId}/star - removes the flag.
     */
    public function unstar(int $requestId): void
    {
        $this->delete($this->action($requestId, 'star'));
    }

    // ----------------------------------------------------------------- settlement

    /**
     * POST /v1/rma-requests/{requestId}/deadline - sets the date the request must be settled by.
     *
     * A DateTimeInterface is formatted as the `Y-m-d` the spec's example shows; pass a string
     * to send some other format verbatim.
     */
    public function setDeadline(int $requestId, string|DateTimeInterface $deadline): void
    {
        $value = $deadline instanceof DateTimeInterface ? $deadline->format('Y-m-d') : $deadline;

        $this->post($this->action($requestId, 'deadline'), $this->identify($requestId, ['deadline' => $value]));
    }

    /**
     * POST /v1/rma-requests/{requestId}/approved-amount - records the amount approved for refund.
     */
    public function setApprovedAmount(int $requestId, float $amount, string $currency): void
    {
        $this->post($this->action($requestId, 'approved-amount'), $this->identify($requestId, [
            'amount' => $amount,
            'currency' => $currency,
        ]));
    }

    /**
     * POST /v1/rma-requests/{requestId}/attachments
     *
     * JSON, not multipart: the endpoint consumes `application/json` with a `files` array of
     * strings. What those strings are - URLs, base64 blobs, previously uploaded ids - is not
     * stated anywhere in the spec, so they are passed through untouched.
     *
     * @param list<string> $files
     */
    public function addAttachments(int $requestId, array $files): void
    {
        $this->post($this->action($requestId, 'attachments'), $this->identify($requestId, ['files' => $files]));
    }

    /**
     * PUT /v1/rma-requests/{requestId}/product/{productId} - settles one returned product.
     */
    public function updateProduct(int $requestId, int $productId, UpdateProduct $update): void
    {
        $path = sprintf('/v1/rma-requests/%d/product/%d', $requestId, $productId);
        $body = ['requestId' => $requestId, 'productId' => $productId] + $update->toArray();

        $this->put($path, $body);
    }

    // ----------------------------------------------------------------- history

    /**
     * GET /v1/rma-requests/{requestId}/timeline - the first page of the request's history,
     * newest first.
     *
     * Use iterateTimeline() for the whole history: a long-running request can outgrow one
     * page, and this method would then return a prefix while totalItems() reports the rest.
     *
     * @return ResourceCollection<TimelineEntry>
     */
    public function timeline(int $requestId): ResourceCollection
    {
        return ResourceCollection::fromPayload(
            $this->fetch($this->action($requestId, 'timeline')),
            TimelineEntry::class,
        );
    }

    /**
     * The whole history, lazily, by following Hydra's view.next.
     *
     * @return Generator<int, TimelineEntry>
     */
    public function iterateTimeline(int $requestId): Generator
    {
        yield from new Paginator(
            $this->transport(),
            $this->action($requestId, 'timeline'),
            TimelineEntry::class,
        );
    }

    // ----------------------------------------------------------------- bulk

    /**
     * POST /v1/rma-requests/bulk/owner - assigns one agent to many requests in one call.
     *
     * The API reports no per-request outcome: the response is an echo of the payload, so
     * the caller cannot learn from it whether any single id was rejected, unknown or already
     * in the target state.
     *
     * @param list<int> $requestIds
     */
    public function bulkAssignOwner(array $requestIds, int $userId): void
    {
        $this->post('/v1/rma-requests/bulk/owner', [
            'requestIds' => $this->requestIds($requestIds),
            'userId' => $userId,
        ]);
    }

    /**
     * POST /v1/rma-requests/bulk/unassign-owner - leaves many requests unassigned.
     *
     * Shares the BulkOwner schema with bulkAssignOwner(), but `userId` is not sent: the spec
     * describes it as "Required for assign, ignored for unassign".
     *
     * Note the verb. The single-request unassignOwner() is a DELETE on the owner path, while
     * the bulk counterpart is a POST to a path of its own.
     *
     * The API reports no per-request outcome: the response is an echo of the payload, so
     * the caller cannot learn from it whether any single id was rejected, unknown or already
     * in the target state.
     *
     * @param list<int> $requestIds
     */
    public function bulkUnassignOwner(array $requestIds): void
    {
        $this->post('/v1/rma-requests/bulk/unassign-owner', ['requestIds' => $this->requestIds($requestIds)]);
    }

    /**
     * POST /v1/rma-requests/bulk/star - flags many requests for attention.
     *
     * Unlike the single-request star(), no `starred` member is sent: BulkStar declares
     * nothing but `requestIds`, and the path carries the intent.
     *
     * The API reports no per-request outcome: the response is an echo of the payload, so
     * the caller cannot learn from it whether any single id was rejected, unknown or already
     * in the target state.
     *
     * @param list<int> $requestIds
     */
    public function bulkStar(array $requestIds): void
    {
        $this->post('/v1/rma-requests/bulk/star', ['requestIds' => $this->requestIds($requestIds)]);
    }

    /**
     * POST /v1/rma-requests/bulk/unstar - removes the flag from many requests.
     *
     * A POST, where the single-request unstar() is a DELETE.
     *
     * The API reports no per-request outcome: the response is an echo of the payload, so
     * the caller cannot learn from it whether any single id was rejected, unknown or already
     * in the target state.
     *
     * @param list<int> $requestIds
     */
    public function bulkUnstar(array $requestIds): void
    {
        $this->post('/v1/rma-requests/bulk/unstar', ['requestIds' => $this->requestIds($requestIds)]);
    }

    /**
     * POST /v1/rma-requests/bulk/status - moves many requests to the same workflow state.
     *
     * The wire field is `statusId`, not the `stateIdentifier` the single-request changeStatus()
     * sends - the two endpoints genuinely disagree in the spec, and the SDK follows each rather
     * than inventing a consistent name neither server would accept. The PHP parameter is named
     * `$stateIdentifier` regardless, so a named argument reads the same on both methods.
     *
     * The API reports no per-request outcome: the response is an echo of the payload, so
     * the caller cannot learn from it whether any single id was rejected, unknown or already
     * in the target state.
     *
     * @param list<int> $requestIds
     */
    public function bulkChangeStatus(array $requestIds, string $stateIdentifier): void
    {
        $this->post('/v1/rma-requests/bulk/status', [
            'requestIds' => $this->requestIds($requestIds),
            'statusId' => $stateIdentifier,
        ]);
    }

    // ----------------------------------------------------------------- internals

    /**
     * Normalises the id list for the wire.
     *
     * The spec declares `requestIds` as `items: {type: string}` while every example is a list
     * of integers, so ints are what goes out. Values that are not integers are rejected rather
     * than coerced: intval('12a') is 12 and intval('abc') is 0, so a single malformed id would
     * quietly retarget a real, unrelated RMA request - and since these operations return void
     * and the API reports no per-request outcome, nobody would ever find out.
     *
     * The spec now caps every bulk schema at 100 ids per call (`minItems: 1, maxItems: 100`);
     * that limit is not enforced here, so an oversized list is left for the server's own
     * ValidationException to reject.
     *
     * array_values() guarantees a JSON array rather than an object: a $requestIds that came
     * out of array_filter() keeps its original keys, and json_encode would then emit
     * `{"1":2}` where the endpoint expects `[2]`.
     *
     * @param list<int> $requestIds
     *
     * @return list<int>
     *
     * @throws ConfigurationException when a value is not an integer id
     */
    private function requestIds(array $requestIds): array
    {
        $normalised = [];

        foreach ($requestIds as $position => $requestId) {
            $normalised[] = self::requestId($requestId, $position);
        }

        return $normalised;
    }

    /**
     * @throws ConfigurationException
     */
    private static function requestId(mixed $value, int|string $position): int
    {
        if (is_int($value)) {
            return $value;
        }

        // A caller reading `list<int>` off the signature may still be holding strings from a
        // request payload or a database driver, so digit strings are accepted - but only those.
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw ConfigurationException::invalidRequestId($value, $position);
    }

    /**
     * Every action hangs off `/v1/rma-requests/{requestId}/...`. Keeping the template in one
     * place means a future rename of that segment is a one-line change here, not sixteen
     * edits scattered across the class. updateProduct() is the one action that does not go
     * through here, because its path takes a second id.
     */
    private function action(int $requestId, string $action): string
    {
        return sprintf('/v1/rma-requests/%d/%s', $requestId, $action);
    }

    /**
     * Every action schema declares `requestId` in the body even though the path already
     * carries it. It is sent anyway: the SDK fills both from the same argument so the two can
     * never disagree, and this works whether the server reads the id from the URI variable or
     * from the denormalised payload. Omitting it would only work under the first reading.
     * To be confirmed by a smoke test against a live key.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function identify(int $requestId, array $body): array
    {
        return ['requestId' => $requestId] + $body;
    }

    protected function model(): string
    {
        return RmaRequest::class;
    }

    protected function collectionPath(): string
    {
        return '/v1/rma-requests';
    }

    protected function itemPath(): string
    {
        return '/v1/rma-requests/{id}';
    }
}
