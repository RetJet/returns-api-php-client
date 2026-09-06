# returns-api-php-client

**English** · [Polski](docs/pl/README.md)

[![CI](https://github.com/RetJet/returns-api-php-client/actions/workflows/ci.yml/badge.svg)](https://github.com/RetJet/returns-api-php-client/actions/workflows/ci.yml)

PHP client for the **RetJet API** - a library for handling returns and warranty claims,
usable in any PHP project, including Symfony and Laravel. No framework dependencies: it talks
to whatever PSR-18 HTTP client your project already has.

> **Status:** pre-`v1.0.0`. The package covers the whole OpenAPI document as production serves
> it, but its own public API can still change in a minor release - see
> [Compatibility](#compatibility) and [CHANGELOG.md](CHANGELOG.md).

## Installation

```bash
composer require retjet/returns-api-php-client
```

Requires PHP `^8.2` and any PSR-18 / PSR-17 implementation, discovered automatically via
`php-http/discovery`. If your project has none, add one:

```bash
composer require guzzlehttp/guzzle
# or: composer require symfony/http-client nyholm/psr7
```

This SDK does not issue API keys itself - it only calls the RetJet API with one you
already have. If you do not have one yet, request access at [retjet.com](https://retjet.com).

## Quick start

```php
use RetJetApi\Returns\Client;

$client = Client::create('YOUR_API_KEY');

$rma = $client->rmaRequests()->get(1234);

echo $rma->identifier;        // RMA-2024-001234
echo $rma->state?->label;     // in_progress
echo $rma->customer?->email;  // john.doe@example.com
```

The API key is the only required input; everything else has a default. The whole SDK is
reachable from that single `use` statement - there is no separate facade class.

## Configuration

`Client::create()` is a shortcut for the builder with default settings. Reach for
`Client::builder()` when you need to override any of them:

```php
use RetJetApi\Returns\Client;

$client = Client::builder()
    ->withApiKey($key)                          // required
    ->withBaseUri('https://api.example.com')    // optional - default: api.retjet.com
    ->withLogger($psrLogger)                    // optional - otherwise no logging
    ->withRetry(maxRetries: 5)                  // optional - default: 3
    ->withTimeout(30)                           // optional - default: 10 s
    ->withUserAgent('my-app/2.0')               // optional
    ->build();
```

| Setting | Default | Override when |
|---|---|---|
| `baseUri` | `https://api.retjet.com` | tests, staging/custom environments, custom proxy |
| `maxRetries` | `3` | `withRetry(0)` disables retrying entirely |
| `timeout` | **`10` s - enforced for a client the SDK builds itself** (minimum `1`) | slow connections, large attachments |
| HTTP client | resolved by `php-http/discovery` | several PSR-18 implementations in the project |
| logger | none | debugging, auditing |
| user agent | `retjet-returns-api-php-client/<installed version>` | you need your own application in the header |

The `timeout` default is deliberately qualified. PSR-18 has no notion of a timeout, so the
SDK can only apply one to a client it constructs itself - it knows how to configure
`symfony/http-client`, `guzzlehttp/guzzle` (7 and newer) and `php-http/curl-client`. When
`php-http/discovery` resolves some other implementation, **the 10 s default is not enforced**
and the client's own defaults apply. Calling `withTimeout()` explicitly in that situation
fails loudly with a `ConfigurationException` rather than silently doing nothing.

### Timeouts and your own HTTP client

PSR-18 has no notion of a timeout: `sendRequest()` blocks and cannot be interrupted from
the outside, so a timeout can only be applied while the client is being constructed.

**`withHttpClient()` and `withTimeout()` are therefore mutually exclusive** - combining them
throws a `ConfigurationException` rather than accepting a timeout it could not honour:

```php
// Let the SDK build the client, and it will honour the timeout.
$client = Client::builder()->withApiKey($key)->withTimeout(30)->build();

// Bring your own client, and the timeout is yours to configure on it.
$client = Client::builder()
    ->withApiKey($key)
    ->withHttpClient(new GuzzleHttp\Client(['timeout' => 30]))
    ->build();
```

## Endpoints

All 34 operations of the OpenAPI document are covered, plus one endpoint that exists only in
the Hydra documentation. The naming is regular: a collection, its items and every action share
one plural segment (`/v1/sale-channels` and `/v1/sale-channels/{id}`). It was not always so -
items and actions sat under a singular segment until 2026-09 - so a client written against an
older description of this API targets URLs that now return 404.

### RMA requests

| Operation | Method |
|---|---|
| `GET /v1/rma-requests` | `$client->rmaRequests()->list(page: 1)` · `iterate()` |
| `GET /v1/rma-requests/{id}` | `$client->rmaRequests()->get($id)` |
| `POST /v1/rma-requests` | `$client->rmaRequests()->create(new CreateRmaRequest(...))` |

### RMA request actions

Every action takes `$requestId` as its first argument. There is no intermediate object, so
`$client->rmaRequests()->` lists the whole resource in one place.

| Operation | Method |
|---|---|
| `POST /v1/rma-requests/{requestId}/status` | `changeStatus($requestId, $stateIdentifier)` |
| `POST /v1/rma-requests/{requestId}/owner` | `assignOwner($requestId, $userId)` |
| `DELETE /v1/rma-requests/{requestId}/owner` | `unassignOwner($requestId)` |
| `POST /v1/rma-requests/{requestId}/follower` | `addFollower($requestId, $userId = null)` |
| `DELETE /v1/rma-requests/{requestId}/follower` | `removeFollower($requestId)` |
| `GET /v1/rma-requests/{requestId}/followers` | `followers($requestId)` · `iterateFollowers($requestId)` |
| `POST /v1/rma-requests/{requestId}/message` | `addMessage($requestId, $message, $public = false)` |
| `POST /v1/rma-requests/{requestId}/stamp` | `addStamp($requestId, $stampId)` |
| `POST /v1/rma-requests/{requestId}/star` | `star($requestId)` |
| `DELETE /v1/rma-requests/{requestId}/star` | `unstar($requestId)` |
| `POST /v1/rma-requests/{requestId}/deadline` | `setDeadline($requestId, $deadline)` |
| `POST /v1/rma-requests/{requestId}/approved-amount` | `setApprovedAmount($requestId, $amount, $currency)` |
| `POST /v1/rma-requests/{requestId}/attachments` | `addAttachments($requestId, $files)` |
| `PUT /v1/rma-requests/{requestId}/product/{productId}` | `updateProduct($requestId, $productId, new UpdateProduct(...))` |
| `GET /v1/rma-requests/{requestId}/timeline` | `timeline($requestId)` · `iterateTimeline($requestId)` |

### Bulk operations

| Operation | Method |
|---|---|
| `POST /v1/rma-requests/bulk/owner` | `bulkAssignOwner($requestIds, $userId)` |
| `POST /v1/rma-requests/bulk/unassign-owner` | `bulkUnassignOwner($requestIds)` |
| `POST /v1/rma-requests/bulk/star` | `bulkStar($requestIds)` |
| `POST /v1/rma-requests/bulk/unstar` | `bulkUnstar($requestIds)` |
| `POST /v1/rma-requests/bulk/status` | `bulkChangeStatus($requestIds, $stateIdentifier)` |

### Reference data

| Operation | Method |
|---|---|
| `GET /v1/sale-channels` | `$client->saleChannels()->list(page: 1)` · `iterate()` |
| `GET /v1/sale-channels/{id}` | `$client->saleChannels()->get($id)` |
| `GET /v1/return-points` | `$client->returnPoints()->list(page: 1)` · `iterate()` |
| `GET /v1/return-points/{id}` | `$client->returnPoints()->get($id)` |
| `GET /v1/ordered-products` | `$client->orderedProducts()->list(page: 1)` · `iterate()` |
| `GET /v1/rma-request-items-conditions` | `$client->itemConditions()->list(page: 1)` · `iterate()` |
| `GET /v1/rma-request-items-conditions/{id}` | `$client->itemConditions()->get($id)` |
| `GET /v1/rma-request-items-reasons` | `$client->itemReasons()->list(page: 1)` · `iterate()` |
| `GET /v1/rma-request-items-reasons/{id}` | `$client->itemReasons()->get($id)` |
| `GET /v1/rma-request-items-resolutions` | `$client->itemResolutions()->list(page: 1)` · `iterate()` |
| `GET /v1/rma-request-items-resolutions/{id}` | `$client->itemResolutions()->get($id)` |

`orderedProducts()` has no `get()` on purpose: the API publishes no
`/v1/ordered-product/{id}` operation.

## Collections and pagination

Two methods, because these are two different needs:

```php
// One page. Use it when the page number comes from outside - a paginated UI, a job argument.
$page = $client->rmaRequests()->list(page: 2);

$page->totalItems();   // size of the whole result set
count($page);          // size of this page - not the same number
$page->hasNextPage();
$page->nextPage();     // the Hydra view.next link, or null

foreach ($page as $rma) {
    // ...
}

// Every page, lazily. Nothing is fetched until the iteration starts, and page N+1 is only
// requested once page N has been consumed - so this is safe for result sets larger than memory.
foreach ($client->rmaRequests()->iterate() as $rma) {
    // ...
}
```

`iterate()` is not called `all()` on purpose: it performs one HTTP request per page and never
holds the whole result set in memory.

Sub-collections follow the same pattern:

```php
$followers = $client->rmaRequests()->followers(1234);          // one page
$timeline  = $client->rmaRequests()->timeline(1234);           // one page

foreach ($client->rmaRequests()->iterateFollowers(1234) as $follower) {
    // ...
}

foreach ($client->rmaRequests()->iterateTimeline(1234) as $entry) {
    // ...
}
```

Collections are requested with `Accept: application/ld+json`. That is not cosmetic: the plain
JSON representation is a bare array with no `totalItems` and no `view` links, which makes
pagination impossible.

The API takes no page-size parameter - `page` is the only one it accepts. A page held **30
records** when this was last measured against a live environment, but nothing in the API
guarantees that number, so read `count($page)` rather than assuming it.

`list(int $page = 1, array $query = [])` and `iterate(array $query = [])` pass `$query` straight
through as extra query-string parameters, merged with `page`. The OpenAPI document does not
declare any filter parameters, so which keys the API actually honours is unconfirmed - $query
is an escape hatch for whatever Hydra's `search` template turns out to support, not a documented
filter API. `page` inside $query is overwritten by the `$page` argument, so use the argument, not
`$query['page']`, to pick a page.

### Nested objects

`RmaRequest::$items`, `$confirmations`, `$attachments` and `$saleChannel` are typed nested
objects - `RmaRequestItem[]`, `RmaRequestFile[]` and `SaleChannel` respectively:

```php
$rma = $client->rmaRequests()->get(1234);

foreach ($rma->items ?? [] as $item) {
    echo $item->requestedQty;
    echo $item->orderedProduct?->name;
}

echo $rma->saleChannel?->label;
```

They stay `null` when the API omits the member entirely - a different statement than "there are
none of them", which the API expresses as an empty list. Anything the SDK does not type yet -
JSON-LD members included, and any field the API adds after this release - is still reachable
through `raw()`, which returns the whole decoded payload untouched.

## Writes and actions

```php
use RetJetApi\Returns\Request\CreateRmaRequest;
use RetJetApi\Returns\Request\CreateRmaRequestCustomer;
use RetJetApi\Returns\Request\CreateRmaRequestItem;
use RetJetApi\Returns\Request\UpdateProduct;

$rma = $client->rmaRequests()->create(new CreateRmaRequest(
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
));

echo $rma->id;   // assigned by the server

$client->rmaRequests()->changeStatus($rma->id, 'in_progress');
$client->rmaRequests()->assignOwner($rma->id, 42);
$client->rmaRequests()->addMessage($rma->id, 'We have received your return.', public: true);
$client->rmaRequests()->setApprovedAmount($rma->id, 99.99, 'PLN');
$client->rmaRequests()->updateProduct($rma->id, 42, new UpdateProduct(confirmedQty: 1));
```

`saleChannelId`, `customer` and `items` are required - the API rejects a create call without
them. Payload objects otherwise only send the members you set - an unset member is omitted
rather than sent as an explicit `null`, because those two say different things to the server.
Anything the SDK does not name yet can go through `extra`:

```php
new CreateRmaRequest(
    saleChannelId: 7,
    customer: new CreateRmaRequestCustomer(/* ... */),
    items: [/* ... */],
    extra: ['fieldAddedByTheApiLater' => 'value'],
);
```

### Actions return void

Every action endpoint answers with an echo of the payload just sent, or with `204`. None of
them returns the updated request, so **after an action you need a fresh `get()` to see the new
state**:

```php
$client->rmaRequests()->changeStatus(1234, 'in_progress');

$updated = $client->rmaRequests()->get(1234);   // the only way to observe the new state

echo $updated->state?->label;
```

Success is signalled by the absence of an exception. Widening a `void` method to return a
value later is not a breaking change, so if the API ever starts returning something useful it
can be added without disruption.

### Bulk operations do not report per-request results

The bulk endpoints answer with an echo of the ID list that was sent. There is no per-ID status
in the response, so **you cannot tell which IDs succeeded and which failed** - the SDK cannot
invent that information:

```php
$client->rmaRequests()->bulkChangeStatus([1, 2, 3], 'closed');
// Returns void. If ID 2 did not exist, nothing here will say so.
```

When the outcome per request matters, either loop over the single-request actions or re-read
the affected requests afterwards. Non-integer IDs are rejected locally with a
`ConfigurationException` rather than coerced - a silent `(int) '12a'` would target request 12,
a real and unrelated record.

### removeFollower cannot unfollow another user

`DELETE /v1/rma-requests/{requestId}/follower` accepts neither a request body nor a query
parameter, so the only follower it can remove is the authenticated user. `removeFollower()`
takes only `$requestId` for that reason - there is nowhere to send a second user's id, and a
server ignoring an undeclared body would quietly remove your own subscription instead:

```php
$client->rmaRequests()->removeFollower(1234);   // unfollows the authenticated user
```

## Error handling

Every exception this library throws implements `RetJetException`, so a single `catch` covers
the whole SDK:

```php
use RetJetApi\Returns\Exception\NotFoundException;
use RetJetApi\Returns\Exception\RetJetException;
use RetJetApi\Returns\Exception\ValidationException;

try {
    $client->rmaRequests()->create($payload);
} catch (ValidationException $e) {
    foreach ($e->violations() as $propertyPath => $messages) {
        // ['saleChannelId' => ['This value should not be blank.']]
    }
} catch (NotFoundException $e) {
    // ...
} catch (RetJetException $e) {
    // anything else the SDK can throw
}
```

### Status to exception mapping

| Condition | Exception |
|---|---|
| `400 Bad Request` | `ApiException` |
| `401 Unauthorized` | `AuthenticationException` |
| `403 Forbidden` | `AccessDeniedException` |
| `404 Not Found` | `NotFoundException` |
| `422 Unprocessable Entity` | `ValidationException` |
| `429 Too Many Requests` | `RateLimitException` |
| any other `4xx` | `ApiException` |
| any `5xx` | `ServerException` |
| network failure, no response | `TransportException` |
| success status, unusable body | `MalformedResponseException` |
| local misconfiguration | `ConfigurationException` |

`400` is listed separately from `422` because it is a documented response of this API, not a
theoretical case: `POST /v1/rma-requests` declares both. A malformed request shape gives you
`400` and a plain `ApiException`, while a well-formed request that fails validation gives you
`422` and a `ValidationException` with `violations()`. Catching only `ValidationException` will
therefore miss real input errors.

Every `ApiException` carries the RFC 7807 problem document from the response body, plus the
method and path of the request that failed - no query string, no host, so nothing an error
tracker shouldn't see:

```php
$e->status();                // HTTP status, authoritative
$e->problem()->title();
$e->problem()->detail();
$e->problem()->type();
$e->problem()->instance();
$e->method();                 // "GET"
$e->path();                   // "/v1/rma-requests/1234/follower"
$e->getMessage();             // "HTTP 404: Not Found (GET /v1/rma-requests/1234/follower)"
```

`RateLimitException::retryAfter()` returns the `Retry-After` value in seconds, accepting both
the numeric and the HTTP-date form the header allows.

### Authentication failures carry no detail

An invalid, revoked or missing API key produces `401` / `AuthenticationException`, as you would
expect. Two things about it are worth knowing before you debug one.

**The response body is `text/html`, not `application/problem+json`.** Every other error status
on this API answers with a problem document; `401` answers with the bare string
`Authentication failed`. The SDK handles that without breaking, but there is nothing to read:

```php
use RetJetApi\Returns\Exception\AuthenticationException;

try {
    $client->rmaRequests()->list();
} catch (AuthenticationException $e) {
    $e->status();             // 401
    $e->problem()->detail();  // null - the server sent no problem document
}
```

**A `500` with `detail: "Unable to exchange token"` means the same thing.** The server exchanges
your key for a JWT on every request; when that exchange fails you get a server error rather than
a `401`. This was the normal response to a bad key until August 2026 and may still appear if the
exchange service itself is unhealthy, so treat it as an authentication problem, not an outage:

```php
use RetJetApi\Returns\Exception\ServerException;

try {
    $client->rmaRequests()->list();
} catch (ServerException $e) {
    if ($e->problem()->detail() === 'Unable to exchange token') {
        // The key is wrong, revoked, or the exchange service is down.
    }
}
```

The SDK deliberately does not rewrite that `500` into an `AuthenticationException`: doing so
would mean matching on message text, which is guesswork that breaks the moment the wording
changes.

## Retries

`RetryMiddleware` is in the transport stack by default with three retries. `withRetry(0)`
removes it entirely.

What gets retried depends on the HTTP method, and that is the important part:

| Failure | `GET`, `HEAD`, `PUT`, `DELETE`, `OPTIONS`, `TRACE` | `POST`, `PATCH` |
|---|---|---|
| `429` rate limit | retried | **retried** |
| `5xx` server error | retried | not retried |
| network failure | retried | not retried |
| anything else | not retried | not retried |

A `429` is answered before the request reaches the handler, so nothing was processed and
replaying it is safe whatever the method was.

A `5xx` is the opposite: the server accepted the request and then failed somewhere. A `500`
out of `POST .../message` may well mean the message was created and only the response blew up.
A network failure is worse still, because PSR-18 flattens *"connection refused"* - provably
safe to replay - and *"read timeout after the server already acted"* - not safe at all - into
the same exception, and the two cannot be told apart without inspecting a specific client's
internals.

**The practical consequence is that writes are effectively never retried in this API, since
almost every write is a POST. That is intended, not a gap.** A duplicated RMA request or a
double-applied status change is a worse failure than an error the caller can see and handle.

Timing is exponential backoff from 1 s, doubling per attempt (1 s, 2 s, 4 s). A `429` carrying
`Retry-After` uses the server's number instead. Two ceilings protect the calling process: no
single wait exceeds 60 s and the waits within one call add up to at most 60 s; past either, the
exception is rethrown with `retryAfter()` intact so you can schedule the work rather than block
a worker.

## Logging

`withLogger()` puts a `LoggingMiddleware` into the stack; without it nothing is logged. Every
attempt is recorded, so a retry storm is visible in the log rather than collapsed into one
line.

Two things are never written to the log:

- **Credentials.** Six header names are masked (`Authorization`, `Proxy-Authorization`,
  `Cookie`, `Set-Cookie`, `X-Api-Key`, `X-Auth-Token`), and credentials embedded in a base URI
  (`https://user:pass@host`) are stripped from exception messages before they are recorded.
- **Request and response bodies - with no option to turn them on.** An `RmaRequest` carries a
  customer's e-mail, postal address and `refundBankAccountNo`, and a `CreateRmaRequest` payload
  carries the same. Copying that into a debug log moves personal and banking data into a system
  with different retention and access rules than the API, usually without anyone deciding to.
  What gets recorded is the method, path, query, masked headers, duration and outcome.

## Testing your integration

`Client` is `final readonly`, so it cannot be mocked with a mocking framework - that is
deliberate, not an oversight. The seam is the PSR-18 client underneath it: both
`Client::create()` and `ClientBuilder::withHttpClient()` accept any `Psr\Http\Client\ClientInterface`,
so a hand-written stub (or `php-http/mock-client`, or a real client pointed at a local fixture
server) stands in for the network without touching `Client` itself:

```php
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class FakeHttpClient implements ClientInterface
{
    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->response;
    }
}

$factory = new Nyholm\Psr7\Factory\Psr17Factory();
$response = $factory->createResponse(200)
    ->withHeader('Content-Type', 'application/ld+json')
    ->withBody($factory->createStream(json_encode(['member' => []])));

$client = Client::create('test-key', new FakeHttpClient($response));
$page = $client->saleChannels()->list();
```

Nothing about `Client` needs mocking: everything it does - hydration, pagination, retries,
exception mapping - runs against the response your stub hands back.

## Compatibility

This project follows [Semantic Versioning](https://semver.org/); before `v1.0.0`, breaking
changes may still land in a minor release (see the Status note above). What the promise
covers, once tagged:

- **Covered:** public classes in `src/`, their public methods and constructors, and the
  exception hierarchy.
- **Not covered:** anything marked `@internal` (`Model\Hydration`, `Http\Redact`,
  `Http\RequestBuilder`, `Http\ResponseParser`, `Http\HttpClientFactory` today - see
  [docs/en/api-reference.md](https://github.com/RetJet/returns-api-php-client/blob/main/docs/en/api-reference.md#not-part-of-the-public-api))
  and anything marked `@experimental` (nothing carries that marker today).
- **Models may gain new properties in a minor release.** A field the API adds is either typed
  later or stays reachable through `raw()`; neither breaks code that does not depend on the
  new field's absence.
- **An action method returning `void` today may start returning a value.** Every action's
  response is currently a payload echo or a `204`, so nothing is discarded now, and widening
  `void` to a real return type is additive, not breaking.
- **`array $query` on `list()`/`iterate()` is not a validated filter API.** Which keys the
  server actually honours depends on the API, not the SDK, and can change independently of
  this package's version.

Everything else - method signatures, constructor parameter order, `toArray()`/`fromArray()`
round-tripping - follows normal semver.

## Documentation

- [API reference](https://github.com/RetJet/returns-api-php-client/blob/main/docs/en/api-reference.md) - every public class and method, endpoints included
- [Symfony integration](https://github.com/RetJet/returns-api-php-client/blob/main/docs/en/symfony.md)
- [Laravel integration](https://github.com/RetJet/returns-api-php-client/blob/main/docs/en/laravel.md)
- [Extending the client](https://github.com/RetJet/returns-api-php-client/blob/main/docs/en/extending.md)
- [Contributing](https://github.com/RetJet/returns-api-php-client/blob/main/CONTRIBUTING.md)
- [Security policy](SECURITY.md)
- [Changelog](CHANGELOG.md)

## License

MIT - see [LICENSE](LICENSE).
