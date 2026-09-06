# API reference

**English** · [Polski](../pl/api-reference.md)

Every public class and method of the SDK, in one place. The [README](../../README.md) explains
when to reach for what; this page is the exhaustive list, including the parts that have no
endpoint behind them - collections, models, payloads, exceptions and the transport stack.

Anything not listed here is internal and may change without a major version bump.

## Entry point

### Client

`RetJetApi\Returns\Client` - `final readonly`. Holds the transport and the configuration, and
hands out the eight resources.

| Member | Signature | Notes |
|---|---|---|
| `create()` | `static create(string $apiKey, ?ClientInterface $httpClient = null): self` | The whole configuration most callers need. The optional PSR-18 client is for containers that already own one; leave it out and the SDK builds one honouring `Configuration::$timeout` |
| `builder()` | `static builder(): ClientBuilder` | Fluent configuration, for everything `create()` does not cover |
| `__construct()` | `__construct(Transport $transport, Configuration $configuration, ?LoggerInterface $logger = null)` | Direct construction, for a DI container that assembles the stack itself |
| `configuration()` | `configuration(): Configuration` | The resolved settings, defaults included |
| `transport()` | `transport(): Transport` | The outermost decorator of the stack |
| `logger()` | `logger(): ?LoggerInterface` | `null` unless `withLogger()` was called |

Resource accessors - one per resource, none of them memoised (the resources are stateless):

```php
$client->rmaRequests();      // RmaRequests
$client->saleChannels();     // SaleChannels
$client->returnPoints();     // ReturnPoints
$client->orderedProducts();  // OrderedProducts
$client->itemConditions();   // ItemConditions
$client->itemReasons();      // ItemReasons
$client->itemResolutions();  // ItemResolutions
```

### ClientBuilder

`RetJetApi\Returns\ClientBuilder` - every method returns `self`, so they chain. Only
`withApiKey()` is required.

| Method | Signature | Default when omitted |
|---|---|---|
| `withApiKey()` | `withApiKey(string $apiKey): self` | **required** - `build()` throws `ConfigurationException` without it |
| `withBaseUri()` | `withBaseUri(string $baseUri): self` | `https://api.retjet.com`; a trailing slash is stripped |
| `withHttpClient()` | `withHttpClient(ClientInterface $httpClient): self` | resolved by `php-http/discovery` |
| `withTimeout()` | `withTimeout(int $seconds): self` | `10`, minimum `1`; **mutually exclusive with `withHttpClient()`** |
| `withRetry()` | `withRetry(int $maxRetries): self` | `3`; `withRetry(0)` unplugs `RetryMiddleware` entirely |
| `withLogger()` | `withLogger(LoggerInterface $logger): self` | no logging at all |
| `withUserAgent()` | `withUserAgent(string $userAgent): self` | `Configuration::defaultUserAgent()` |
| `withMiddleware()` | `withMiddleware(callable $factory): self` | none; takes a `callable(Transport): Transport` factory, not an instance - the transport being decorated does not exist before `build()` |
| `build()` | `build(): Client` | - |

`withRetry(5)` means **six requests**: one attempt plus five retries.

### Configuration

`RetJetApi\Returns\Configuration` - `final readonly`. Validates in the constructor, so an
unusable setting fails at build time rather than on the first request.

| Constant | Value |
|---|---|
| `DEFAULT_BASE_URI` | `https://api.retjet.com` |
| `DEFAULT_TIMEOUT` | `10` |
| `DEFAULT_MAX_RETRIES` | `3` |

`defaultUserAgent(): string` is a static method rather than a constant: it reads the installed
package version from `Composer\InstalledVersions` at call time
(`retjet-returns-api-php-client/<version> (+https://github.com/RetJet/returns-api-php-client)`),
falling back to `dev` when the version cannot be resolved. The product token is the Composer
package name with its `/` swapped for a `-`, so renaming the package renames the header too. This is what a `Configuration` built without an explicit `$userAgent` uses.

| Property | Type | Rejected when |
|---|---|---|
| `$apiKey` | `string` | blank → `ConfigurationException::missingApiKey()` |
| `$baseUri` | `string` | not an absolute http(s) URI → `invalidBaseUri()`; a trailing slash is normalised away |
| `$timeout` | `int` | `< 1` → `invalidTimeout()`; `0` is refused because Guzzle and cURL read it as "wait forever" |
| `$maxRetries` | `int` | `< 0` → `negativeMaxRetries()` |
| `$userAgent` | `string` | - |

## Resources

All eight extend `AbstractResource` and share the same shape: `list(page: N)` for one page,
`iterate()` for every page lazily, `get($id)` where the API publishes an item endpoint.

### RmaRequests - collection and item

`RetJetApi\Returns\Resource\RmaRequests`. The item (`/v1/rma-requests/{id}`) and every action
below (`/v1/rma-requests/{requestId}/…`) share one plural segment.

| Method | Endpoint | Returns |
|---|---|---|
| `list(int $page = 1)` | `GET /v1/rma-requests?page=N` | `ResourceCollection<RmaRequest>` |
| `iterate()` | `GET /v1/rma-requests`, then `view.next` | `Generator<int, RmaRequest>` |
| `get(int $id)` | `GET /v1/rma-requests/{id}` | `RmaRequest` |
| `create(CreateRmaRequest $request)` | `POST /v1/rma-requests` | `RmaRequest` - the only write that returns a model |

### RmaRequests - actions

Every action takes `$requestId` first and returns `void`: the response is an echo of the
payload just sent, so **a fresh `get()` is the only way to observe the new state**. Success is
the absence of an exception.

The `Body sent` column matters when comparing against server logs - most actions duplicate
`requestId` into the body alongside the path, because the spec does not settle which one the
server reads.

| Method | Endpoint | Body sent |
|---|---|---|
| `changeStatus(int $requestId, string $stateIdentifier)` | `POST /v1/rma-requests/{requestId}/status` | `{requestId, stateIdentifier}` |
| `assignOwner(int $requestId, int $userId)` | `POST /v1/rma-requests/{requestId}/owner` | `{requestId, userId}` |
| `unassignOwner(int $requestId)` | `DELETE /v1/rma-requests/{requestId}/owner` | none |
| `addFollower(int $requestId, ?int $userId = null)` | `POST /v1/rma-requests/{requestId}/follower` | `{userId}`, or `{}` to follow as the authenticated user. **No `requestId`** - this is the one action schema whose every member describes the *user*, so sending the request id would name the wrong one |
| `removeFollower(int $requestId)` | `DELETE /v1/rma-requests/{requestId}/follower` | none. No `$userId` parameter is offered - the endpoint has nowhere to put it, and a server ignoring an undeclared body would drop *your own* follow and still answer `204` |
| `followers(int $requestId)` | `GET /v1/rma-requests/{requestId}/followers` | - returns `ResourceCollection<RmaRequestFollower>`; no `page` parameter exists |
| `iterateFollowers(int $requestId)` | same, then `view.next` | `Generator<int, RmaRequestFollower>` |
| `addMessage(int $requestId, string $message, bool $public = false)` | `POST /v1/rma-requests/{requestId}/message` | `{requestId, message, public}`. Defaults to an internal note: a message the customer can see is not reversible |
| `addStamp(int $requestId, string $stampId)` | `POST /v1/rma-requests/{requestId}/stamp` | `{requestId, stampId}` |
| `star(int $requestId)` | `POST /v1/rma-requests/{requestId}/star` | `{requestId, starred: true}` |
| `unstar(int $requestId)` | `DELETE /v1/rma-requests/{requestId}/star` | none |
| `setDeadline(int $requestId, string\|DateTimeInterface $deadline)` | `POST /v1/rma-requests/{requestId}/deadline` | `{requestId, deadline}`; a `DateTimeInterface` is formatted `Y-m-d`, a string goes out verbatim |
| `setApprovedAmount(int $requestId, float $amount, string $currency)` | `POST /v1/rma-requests/{requestId}/approved-amount` | `{requestId, amount, currency}` |
| `addAttachments(int $requestId, array $files)` | `POST /v1/rma-requests/{requestId}/attachments` | `{requestId, files}` - **JSON, not multipart**. The endpoint consumes `application/json` with a `files` array of strings; what those strings are (URLs, base64, uploaded ids) is stated nowhere in the spec, so they pass through untouched |
| `updateProduct(int $requestId, int $productId, UpdateProduct $update)` | `PUT /v1/rma-requests/{requestId}/product/{productId}` | `{requestId, productId, …UpdateProduct}` |
| `timeline(int $requestId)` | `GET /v1/rma-requests/{requestId}/timeline` | `ResourceCollection<TimelineEntry>`, newest first |
| `iterateTimeline(int $requestId)` | same, then `view.next` | `Generator<int, TimelineEntry>` |

`followers()` and `timeline()` return the **first page only**. Their `totalItems()` reports the
true count, so a caller reading just the collection can silently miss entries - prefer the
`iterate*` variants for anything that can outgrow a page.

### RmaRequests - bulk operations

All five return `void` and **report no per-request outcome**: the response echoes the id list,
with no per-id status, so you cannot learn from it which ids succeeded. When that matters, loop
over the single-request actions or re-read the affected requests.

Non-integer ids are rejected locally with `ConfigurationException::invalidRequestId()` rather
than coerced - a silent `(int) '12a'` would target request 12, a real and unrelated record.

| Method | Endpoint | Body sent |
|---|---|---|
| `bulkAssignOwner(array $requestIds, int $userId)` | `POST /v1/rma-requests/bulk/owner` | `{requestIds, userId}` |
| `bulkUnassignOwner(array $requestIds)` | `POST /v1/rma-requests/bulk/unassign-owner` | `{requestIds}` - `userId` is not sent; the spec calls it "ignored for unassign" |
| `bulkStar(array $requestIds)` | `POST /v1/rma-requests/bulk/star` | `{requestIds}` - no `starred` member, unlike single-request `star()` |
| `bulkUnstar(array $requestIds)` | `POST /v1/rma-requests/bulk/unstar` | `{requestIds}` |
| `bulkChangeStatus(array $requestIds, string $stateIdentifier)` | `POST /v1/rma-requests/bulk/status` | `{requestIds, statusId: $stateIdentifier}` - the wire field is **`statusId`**, where the single-request `changeStatus()` sends `stateIdentifier`. The two endpoints genuinely disagree in the spec; only the PHP parameter name is unified |

Two traps worth repeating, because they invert what the single-request actions do:

- `unassign-owner` and `unstar` are **POST**, while single `unassignOwner()` and `unstar()` are DELETEs.
- `requestIds` is serialised as `int[]`, not `string[]`, despite the spec declaring strings.

### Reference data resources

Identical shape, one model each. An item path is its collection path plus an id.

| Accessor | Class | `list()` / `iterate()` | `get($id)` |
|---|---|---|---|
| `saleChannels()` | `SaleChannels` | `GET /v1/sale-channels` | `GET /v1/sale-channels/{id}` |
| `returnPoints()` | `ReturnPoints` | `GET /v1/return-points` | `GET /v1/return-points/{id}` |
| `orderedProducts()` | `OrderedProducts` | `GET /v1/ordered-products` | **none** |
| `itemConditions()` | `ItemConditions` | `GET /v1/rma-request-items-conditions` | `GET /v1/rma-request-items-conditions/{id}` |
| `itemReasons()` | `ItemReasons` | `GET /v1/rma-request-items-reasons` | `GET /v1/rma-request-items-reasons/{id}` |
| `itemResolutions()` | `ItemResolutions` | `GET /v1/rma-request-items-resolutions` | `GET /v1/rma-request-items-resolutions/{id}` |

`OrderedProducts` has no `get()` because the API publishes no `/v1/ordered-products/{id}`
operation. A resource without an item endpoint throws `ConfigurationException::noItemEndpoint()`
rather than sending a request that could only 404.

## Collections

### ResourceCollection

`RetJetApi\Returns\Collection\ResourceCollection<T of Model>` - `final readonly`, implements
`Countable` and `IteratorAggregate`. One page: the hydrated members plus the Hydra metadata
that came with them.

| Method | Returns | Notes |
|---|---|---|
| `fromPayload(array $payload, string $model)` | `self<TModel>` | `static`; hydrates a decoded collection payload |
| `member()` | `list<T>` | the hydrated members of this page |
| `totalItems()` | `int` | size of the **whole** result set |
| `count()` | `int` | size of **this page** - a different number as soon as the set spans more than one page |
| `view()` | `array<string, string>` | `view` members: `first`, `last`, `previous`, `next`, from a Hydra envelope or the `Link` header |
| `nextPage()` | `?string` | the next-page URL, or `null` on the last page |
| `previousPage()` | `?string` | the `view.previous` URL, or `null` |
| `hasNextPage()` | `bool` | - |
| `first()` | `?Model` | first member of this page |
| `isEmpty()` | `bool` | - |
| `getIterator()` | `ArrayIterator<int, T>` | `foreach` over this page |
| `toArray()` | `array` | every member through `Model::toArray()` |

Conflating `count()` with `totalItems()` is the classic pagination bug, which is why they are
two names rather than one:

```php
$page = $client->rmaRequests()->list(page: 2);

$page->totalItems();   // the whole result set
count($page);          // this page only
```

The API takes no page-size parameter - `page` is the only one it accepts. A page held **30
records** when last measured against a live environment, but nothing in the API guarantees
that, so read `count($page)`.

### Paginator

`RetJetApi\Returns\Collection\Paginator<T of Model>` - `final`, implements `IteratorAggregate`.
Walks every page by following the server's own next link rather than incrementing a counter,
which keeps the SDK correct if the API ever changes how it paginates.

| Method | Returns | Notes |
|---|---|---|
| `getIterator()` | `Generator<int, T>` | every item of every page, in order |
| `pages()` | `Generator<int, ResourceCollection<T>>` | the pages themselves, for consumers that need `totalItems()` while iterating |

Nothing is fetched until iteration starts, and page N+1 is only requested once page N has been
consumed - so this is safe for result sets larger than memory. A `view.next` pointing back at
the page just fetched is treated as the end, since a proxy rewriting links could otherwise loop
forever.

`pages()` is the way to keep the metadata while streaming:

```php
foreach ($client->rmaRequests()->iterate() as $rma) {
    // one item at a time, one HTTP request per page
}
```

**Absolute URLs that leave the configured base URI are refused, not followed.** Every request
carries the API key and `view.next` is server-controlled data, so a spoofed link would hand the
key to a foreign host; the same-origin check compares scheme, host and port, and raises
`ConfigurationException::crossOriginRequest()`.

## Models

### The Model interface

`RetJetApi\Returns\Model\Model` - implemented by all fourteen models. They are `final readonly`
with public properties rather than accessors, and every property is nullable, because the spec
marks almost nothing as required.

| Member | Signature | Notes |
|---|---|---|
| `fromArray()` | `static fromArray(array $data): static` | hydrates from a decoded payload |
| `toArray()` | `toArray(): array` | the typed properties, with API key names restored |
| `raw()` | `raw(): array` | **the whole decoded payload**, not just the unknown keys |

`raw()` is what makes the SDK forward compatible: a member the API adds tomorrow is reachable
today, and hydration never breaks over a field it does not know.

### RmaRequest

The central resource. Timestamps are Unix seconds, as sent by the API.

| Property | Type | Notes |
|---|---|---|
| `$id` | `?int` | |
| `$uuid` | `?string` | |
| `$identifier` | `?string` | e.g. `RMA-2024-001234` |
| `$type` | `?string` | e.g. `return`, `warranty` |
| `$createdAt` | `?int` | Unix seconds |
| `$deadlineTs` | `?int` | Unix seconds |
| `$customer` | `?RmaRequestCustomer` | one of only two genuinely embedded objects |
| `$customerLocale` | `?string` | |
| `$customerInfo` | `?string` | |
| `$refundBankAccountNo` | `?string` | |
| `$totalRequestedAmount` | `?float` | |
| `$totalRequestedCurrency` | `?string` | |
| `$totalConfirmedAmount` | `?float` | |
| `$totalConfirmedCurrency` | `?string` | |
| `$state` | `?RmaRequestState` | the other embedded object |
| `$saleChannel` | `?SaleChannel` | embedded object; earlier API versions sent an IRI here instead |
| `$items` | `?list<RmaRequestItem>` | product lines on the request |
| `$confirmations` | `?list<RmaRequestFile>` | documents the returns process generated, e.g. a confirmation PDF |
| `$attachments` | `?list<RmaRequestFile>` | evidence uploaded by the customer or an agent |

`createdAtAsDateTime()` and `deadlineTsAsDateTime()` return the corresponding `?int` as a UTC
`DateTimeImmutable`, or `null` when the source property is `null`.

`items`, `confirmations` and `attachments` stay `null` when the API omits the member entirely -
a different statement than "there are none of them", which the API expresses as an empty list.
These three used to be declared as `string[]` in the spec while plainly being objects in
practice, so the SDK left them untyped in `raw()`; the spec now names their real schemas
(`RmaRequestItem`, `RmaRequestFile`) and the properties are typed accordingly.

### RmaRequestCustomer

| Property | Type |
|---|---|
| `$email` | `?string` |
| `$firstName` | `?string` |
| `$lastName` | `?string` |
| `$country` | `?string` |
| `$state` | `?string` |
| `$city` | `?string` |
| `$zip` | `?string` |
| `$address1` | `?string` |
| `$address2` | `?string` |
| `$phone` | `?string` |
| `$company` | `?string` |
| `$taxId` | `?string` |

### RmaRequestItem

One product line within an RMA request: what was requested, what was confirmed, and the
ordered product it refers to.

| Property | Type | Notes |
|---|---|---|
| `$id` | `?int` | |
| `$requestedQty` | `?int` | |
| `$requestedAmount` | `?float` | |
| `$requestedCurrency` | `?string` | |
| `$confirmedQty` | `?int` | |
| `$confirmedAmount` | `?float` | |
| `$confirmedCurrency` | `?string` | |
| `$label` | `?string` | |
| `$labelTranslated` | `?string` | |
| `$state` | `?string` | |
| `$rmaRequestItemReason` | `?string` | an **IRI**, not an embedded object |
| `$rmaRequestIItemResolution` | `?string` | an **IRI**; the double "I" is the spec's own key, kept verbatim rather than "corrected" |
| `$rmaRequestIItemCondition` | `?string` | an **IRI**; same double-"I" spelling |
| `$orderedProduct` | `?OrderedProduct` | the embedded original product line, or `null` |

### RmaRequestFile

A file attached to an RMA request - a document the returns process generated
(`RmaRequest::$confirmations`) or evidence a customer or agent uploaded
(`RmaRequest::$attachments`).

| Property | Type |
|---|---|
| `$url` | `?string` |
| `$mime` | `?string` |

### RmaRequestState

| Property | Type | Notes |
|---|---|---|
| `$label` | `?string` | the machine identifier - compare against this |
| `$labelTranslated` | `?string` | display form in the account's locale; never use as a key |
| `$state` | `?string` | the underlying workflow state the label belongs to |
| `$labelColor` | `?string` | |

### RmaRequestFollower

Every member describes the **user**, never the RMA request - this is the only action schema
with no member for the request itself, which the path carries alone.

| Property | Type | Notes |
|---|---|---|
| `$id` | `?int` | documented in the spec as "User ID of the follower" |
| `$email` | `?string` | |
| `$name` | `?string` | |
| `$userId` | `?int` | "User ID to follow/unfollow" |

`$id` and `$userId` look redundant and which one a live response fills in is unconfirmed.

### TimelineEntry

`RmaRequestTimeline` in the spec.

| Property | Type | Notes |
|---|---|---|
| `$id` | `?int` | |
| `$type` | `?string` | |
| `$createdAt` | `?int` | Unix seconds |
| `$description` | `?string` | |
| `$public` | `?bool` | visible to the customer, or internal only |

`createdAtAsDateTime()` returns `$createdAt` as a UTC `DateTimeImmutable`, or `null` when it is
`null`.

`user` and `data` stay in `raw()`: the spec declares both as `string[]`, which does not match
what an actor reference and an event payload can plausibly be.

### SaleChannel

| Property | Type | Notes |
|---|---|---|
| `$id` | `?int` | |
| `$label` | `?string` | |
| `$name` | `?string` | |
| `$channelType` | `?string` | |
| `$maxReturnDaysProcessingPolicy` | `?int` | |
| `$maxWarrantyDaysProcessingPolicy` | `?int` | |
| `$returnPointAddress` | `?ReturnPoint` | embedded object; present when the channel is read on its own - a list response typically carries `$returnPointId` alone |
| `$returnPointId` | `?int` | always present |

### ReturnPoint

A physical address returned goods are sent back to. All properties `?string` except `$id`.

| Property | Type |
|---|---|
| `$id` | `?int` |
| `$customLabel` | `?string` |
| `$name` | `?string` |
| `$country` | `?string` |
| `$state` | `?string` |
| `$city` | `?string` |
| `$zip` | `?string` |
| `$address1` | `?string` |
| `$address2` | `?string` |
| `$contactPhone` | `?string` |
| `$contactEmail` | `?string` |

### OrderedProduct

A product line from the original order. Read-only in this API: a collection endpoint, no item
endpoint.

| Property | Type |
|---|---|
| `$id` | `?int` |
| `$name` | `?string` |
| `$cover` | `?string` |
| `$sku` | `?string` |
| `$price` | `?float` |
| `$currency` | `?string` |
| `$quantity` | `?int` |
| `$remoteOrder` | `?string` |
| `$orderId` | `?string` |
| `$productId` | `?string` |
| `$lineId` | `?string` |

`$orderId`, `$productId` and `$lineId` are the sale channel's own identifiers - pass them back
as `CreateRmaRequestItem::$orderId`/`$productId`/`$lineId` when creating a return.

### ItemCondition, ItemReason, ItemResolution

Three independent dictionary classes with no shared base - they describe different things and
mostly share the same shape.

| Property | Type | Notes |
|---|---|---|
| `$id` | `?int` | |
| `$label` | `?string` | the stable machine identifier to compare against |
| `$labelTranslated` | `?string` | display form in the account's locale; must not be used as a key |
| `$isActive` | `?bool` | whether the option is still offered by the shop |
| `$position` | `?int` | display order in the shop configuration |

`ItemReason` and `ItemResolution` additionally type `$isReturn` and `$isWarranty` (`?bool`,
whether the option applies to returns / warranty claims) and `$returnPosition` (`?int`, display
order within the return flow). `ItemResolution` alone also types `$warrantyPosition` (`?int`,
display order within the warranty flow).

## Request payloads

Payload objects send **only the members you set**. An unset member is omitted rather than sent
as an explicit `null`, because those two say different things to a server.

### Payload

`RetJetApi\Returns\Request\Payload` - one method, `toArray(): array`. Implement it to pass your
own body object to a resource method.

### CreateRmaRequest

Body for `POST /v1/rma-requests`. The API describes this with a dedicated schema
(`RmaRequest.CreateRmaRequest`) rather than reusing the read-side `RmaRequest` shape, and it is
considerably narrower: `$saleChannelId` is a plain id, not the IRI the read side reports, and
there is no member for `identifier`, `uuid`, `totalRequestedAmount`, `totalRequestedCurrency` or
`customerLocale` - those are server-assigned or server-derived and cannot be set on create.

`$saleChannelId` and `$customer` are required by the schema. `$items` carries no `required` of
its own, but the schema's own description calls it "at least one is required", so the SDK
requires it too rather than trusting an omission that would only produce a `400` from the
server anyway.

| Parameter | Type | Notes |
|---|---|---|
| `$saleChannelId` | `int` | **required** |
| `$customer` | `CreateRmaRequestCustomer` | **required** |
| `$items` | `list<CreateRmaRequestItem>` | **required**, at least one |
| `$type` | `?string` | e.g. `return`, `warranty`; defaults to `return` server-side when omitted |
| `$customerInfo` | `?string` | |
| `$customerInstruction` | `?string` | |
| `$refundBankAccountNo` | `?string` | |
| `$extra` | `array` | fills gaps left by the named parameters above that are still null; a named parameter that is set wins over the same key here |

### CreateRmaRequestCustomer

The `$customer` member of `CreateRmaRequest`. `$email`, `$firstName`, `$lastName`, `$country`,
`$city` and `$address1` are required by the schema; everything else is optional. Unlike the
read-side `RmaRequestCustomer`, the wire keys here are already camelCase - there is no
snake_case to translate.

| Parameter | Type | Notes |
|---|---|---|
| `$email` | `string` | **required** |
| `$firstName` | `string` | **required** |
| `$lastName` | `string` | **required** |
| `$country` | `string` | **required** |
| `$city` | `string` | **required** |
| `$address1` | `string` | **required** |
| `$address2` | `?string` | |
| `$zip` | `?string` | |
| `$state` | `?string` | |
| `$phone` | `?string` | |
| `$company` | `?string` | |
| `$taxId` | `?string` | |
| `$extra` | `array` | |

### CreateRmaRequestItem

One entry of the `$items` member of `CreateRmaRequest`. `$orderId` and `$productId` identify
the ordered product the way the sale channel does - the same values
`OrderedProduct::$orderId`/`$productId` report back on read; `$lineId` disambiguates them
further where the sale channel distinguishes multiple lines of the same product.
`$orderId`, `$productId`, `$quantity`, `$reasonId` and `$conditionId` are required by the
schema; `$resolutionId` and everything else are optional.

| Parameter | Type | Notes |
|---|---|---|
| `$orderId` | `string` | **required** |
| `$productId` | `string` | **required** |
| `$quantity` | `int` | **required** |
| `$reasonId` | `int` | **required**, from `GET /v1/rma-request-items-reasons` |
| `$conditionId` | `int` | **required**, from `GET /v1/rma-request-items-conditions` |
| `$lineId` | `?string` | |
| `$name` | `?string` | |
| `$price` | `?float` | |
| `$currency` | `?string` | |
| `$resolutionId` | `?int` | from `GET /v1/rma-request-items-resolutions` |
| `$extra` | `array` | |

### UpdateProduct

Body for `PUT /v1/rma-requests/{requestId}/product/{productId}` - the confirmed quantity and
amount an agent settles a single returned product at.

| Parameter | Type |
|---|---|
| `$confirmedQty` | `?int` |
| `$confirmedAmount` | `?float` |
| `$confirmedCurrency` | `?string` |
| `$extra` | `array` |

`requestId` and `productId` are not part of this object: they identify the target and are
arguments of `updateProduct()`, which puts them in the path.

## Exceptions

### The hierarchy

Everything the SDK throws implements `RetJetApi\Returns\Exception\RetJetException`, a marker
interface extending `Throwable`, so a single `catch` covers the whole library.

| Class | Extends | Thrown when |
|---|---|---|
| `ApiException` | `RuntimeException` | any `4xx` the SDK does not map to something narrower, `400` included |
| `AuthenticationException` | `ApiException` | `401` |
| `AccessDeniedException` | `ApiException` | `403` |
| `NotFoundException` | `ApiException` | `404` |
| `ValidationException` | `ApiException` | `422` |
| `RateLimitException` | `ApiException` | `429` |
| `ServerException` | `ApiException` | any `5xx` |
| `TransportException` | `RuntimeException` | network failure, no response - wraps the PSR-18 `ClientExceptionInterface` |
| `MalformedResponseException` | `RuntimeException` | success status, unusable body |
| `ConfigurationException` | `InvalidArgumentException` | local misconfiguration, before anything goes to the network |

`400` is listed separately from `422` because it is a documented response of this API, not a
theoretical case - catching only `ValidationException` will miss real input errors.

### ApiException and its subclasses

| Method | Returns | Available on |
|---|---|---|
| `status()` | `int` | every `ApiException`; the HTTP status, authoritative |
| `problem()` | `Problem` | every `ApiException` |
| `method()` | `string` | every `ApiException` - HTTP method of the request that failed, e.g. `"GET"` |
| `path()` | `string` | every `ApiException` - path of the request that failed, no query string and no host |
| `violations()` | `array<string, list<string>>` | `ValidationException` - property path → messages |
| `violationsFor(string $propertyPath)` | `list<string>` | `ValidationException` |
| `retryAfter()` | `?int` | `RateLimitException` - seconds, reading both the numeric and the HTTP-date form the header allows |
| `status()` | `int` | `MalformedResponseException` too, though it is not an `ApiException` |

`method()` and `path()` are also folded into `getMessage()` - `HTTP 404: Not Found (DELETE
/v1/rma-requests/1234/follower)` - so an error tracker that only surfaces the message still shows
which call failed. The query string is deliberately excluded from both: it can carry a Hydra
`view.next` link's caller-supplied parameters, and a request id in the path is not the same kind
of data.

```php
try {
    $client->rmaRequests()->create($payload);
} catch (ValidationException $e) {
    foreach ($e->violations() as $propertyPath => $messages) {
        // ['saleChannelId' => ['This value should not be blank.']]
    }
} catch (RetJetException $e) {
    // anything else the SDK can throw
}
```

**`TransportException` redacts credentials at the source.** It embeds the full request URI, so
a base URI in the form `https://user:pass@host` would otherwise write the password into
whatever caught it. The redaction happens in the exception itself, which protects your own
error handling as well as the SDK's logger.

### Problem

`RetJetApi\Returns\Exception\Problem` - `final readonly`, an RFC 7807 problem document. Carried by
every `ApiException`, and it ignores the `trace[]` member the dev environment appends.

| Method | Returns |
|---|---|
| `fromArray(array $payload, ?int $fallbackStatus = null)` | `static` |
| `type()` | `?string` |
| `title()` | `?string` |
| `status()` | `?int` |
| `detail()` | `?string` |
| `instance()` | `?string` |
| `summary()` | `?string` - the specific `detail`, falling back to the generic `title` |
| `toArray()` | `array` |

Every member is nullable, and on a `401` they are **all** null: unlike every other error status
on this API, an authentication failure answers with `text/html` and the bare string
`Authentication failed` rather than a problem document. A `500` whose `detail()` reads
`Unable to exchange token` means the same thing - the SDK does not rewrite it into an
`AuthenticationException`, because that would mean matching on message text.

### ConfigurationException

Named static factories, each one a specific mistake caught before any request goes out:

| Factory | Raised when |
|---|---|
| `missingApiKey()` | `build()` without `withApiKey()` |
| `invalidBaseUri()` | base URI is not an absolute http(s) URI |
| `invalidTimeout()` | timeout below 1 second |
| `negativeMaxRetries()` | negative retry count |
| `unencodableBody()` | a request body that `json_encode` cannot serialise |
| `timeoutOnSuppliedClient()` | `withTimeout()` combined with `withHttpClient()` |
| `timeoutNotSupported()` | `withTimeout()` on a discovered client the SDK cannot configure |
| `invalidRequestId()` | a bulk id that is neither an int nor a digit string |
| `noItemEndpoint()` | `get()` on a resource with no item endpoint |
| `crossOriginRequest()` | a URL leaving the configured base URI - the API-key leak guard |
| `missingDiscovery()` | `php-http/discovery` found no PSR-17 or PSR-18 implementation |

## Transport and middleware

### Transport

`RetJetApi\Returns\Http\Transport` - one method, deliberately, so a decorator can forward a single
call and replay it without holding state:

```php
public function request(
    string $method,
    string $path,
    array $query = [],
    ?array $body = null,
    array $headers = [],
): array;
```

`$path` is relative, or an absolute URL for a `view.next` link. The return value is the decoded
body; errors arrive as exceptions. `PsrTransport` is the PSR-18 implementation at the bottom of
the stack.

`MiddlewareInterface` extends `Transport` and adds nothing - a middleware **is** a transport,
which is the whole contract. The stack is assembled inside `ClientBuilder::decorate()` in this
order:

```
PsrTransport → LoggingMiddleware → RetryMiddleware → your middleware
```

Logging sits inside retrying, so every attempt gets its own record; your middleware is
outermost, so a cache can answer without waking the retry logic.

### RetryMiddleware

`RetJetApi\Returns\Http\Middleware\RetryMiddleware` - in the stack by default with three retries;
`withRetry(0)` removes it.

| Constructor parameter | Default |
|---|---|
| `Transport $next` | - |
| `int $maxRetries` | `Configuration::DEFAULT_MAX_RETRIES` (3) |
| `?callable $sleeper` | `usleep()`; the injection point exists for tests and for anyone who wants jitter |
| `float $baseDelay` | `DEFAULT_BASE_DELAY` = `1.0` |
| `float $maxDelay` | `DEFAULT_MAX_DELAY` = `60.0` - ceiling on one wait |
| `float $maxTotalDelay` | `DEFAULT_MAX_TOTAL_DELAY` = `60.0` - ceiling on all waits within one call |

What gets retried depends on the HTTP method:

| Failure | `GET`, `HEAD`, `PUT`, `DELETE`, `OPTIONS`, `TRACE` | `POST`, `PATCH` |
|---|---|---|
| `429` rate limit | retried | **retried** |
| `5xx` server error | retried | not retried |
| network failure | retried | not retried |
| anything else | not retried | not retried |

A `429` is answered before the request reaches the handler, so replaying it is safe whatever
the method was. A `5xx` is the opposite - the server accepted the request and then failed
somewhere - and PSR-18 flattens "connection refused" and "read timeout after the server already
acted" into the same exception, so the two cannot be told apart. **Writes are therefore
effectively never retried in this API, since almost every write is a POST. That is intended.**

Timing is exponential backoff from 1 s, doubling per attempt. A `429` carrying `Retry-After`
uses the server's number instead. The two ceilings differ in how they treat an over-long wait,
and the difference is deliberate: our own backoff guess is **clamped** to `maxDelay`, while the
server's `Retry-After` instruction is **obeyed or refused**, never shortened. Past either
ceiling the exception is rethrown with `retryAfter()` intact, so you can schedule the work
rather than block a worker.

### LoggingMiddleware

`RetJetApi\Returns\Http\Middleware\LoggingMiddleware` - inactive until `withLogger()`.

| Constructor parameter | Default |
|---|---|
| `Transport $next` | - |
| `LoggerInterface $logger` | - |
| `string $level` | `LogLevel::DEBUG` |
| `string $errorLevel` | `LogLevel::WARNING` |

Every attempt is recorded, so a retry storm shows up as several lines rather than one. What
gets written is the method, path, query, masked headers, duration and outcome.

Two things are never written, and one of them has no opt-in:

- **Credentials.** Six header names are masked - `Authorization`, `Proxy-Authorization`,
  `Cookie`, `Set-Cookie`, `X-Api-Key`, `X-Auth-Token` - and credentials embedded in a base URI
  are stripped from exception messages.
- **Request and response bodies, with no flag to turn them on.** An `RmaRequest` carries a
  customer's e-mail, postal address and `refundBankAccountNo`, and a `CreateRmaRequest` payload
  carries the same. Copying that into a debug log moves personal and banking data into a system
  with different retention and access rules than the API, usually without anyone deciding to.

A failure is logged as class, message and status rather than as a `Throwable` under an
`exception` key. That departs from the PSR-3 convention on purpose; the exception itself
propagates untouched either way.

## Not part of the public API

These exist in `src/`, and none of them is covered by the compatibility promise:

| Class | What it is |
|---|---|
| `Model\Hydration` | `@internal` type-safe reader used by every `fromArray()` |
| `Http\Redact` | credential redaction used by `TransportException` and the logger |
| `Http\RequestBuilder` | URI, query and header assembly, including the same-origin check |
| `Http\ResponseParser` | JSON decoding, Hydra unwrapping, status → exception mapping |
| `Http\HttpClientFactory` | discovery and timeout configuration for a client the SDK builds |

The `protected` API of `AbstractResource` - `fetch()`, `post()`, `put()`, `delete()`, `item()`,
`collection()`, `paginate()`, `hydrate()` - **is** an extension point, and is documented in
[Extending the client](extending.md).

One known rough edge: `Hydration` is marked `@internal` while `Model` is a public contract, so
writing your own model today means rewriting its logic. This is tracked as a follow-up.
