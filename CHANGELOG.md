# Changelog

**English** · [Polski](docs/pl/CHANGELOG.md)

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0-beta.1] - 2026-09-06

First tagged release. The package covers the whole OpenAPI document as production serves it;
the open question below is a pre-existing gap in the underlying API, not a blocker for this tag.

### Added

- `Client::create()` and `Client::builder()` as the single entry point; the API key is the
  only required setting.
- All 34 operations of the OpenAPI document, every one of them under the plural path segment
  production serves (`/v1/rma-requests/{id}`, `/v1/rma-requests/{requestId}/status`,
  `/v1/sale-channels/{id}` and so on). An earlier draft of this client was written against a
  description in which items and actions sat under a singular segment; that description no
  longer matches any environment.
- `CreateRmaRequest`, with `CreateRmaRequestCustomer` and `CreateRmaRequestItem` for the
  required `customer` and `items` members, built around the dedicated create schema rather than
  the full `RmaRequest` shape. `type` selects `return` or `warranty`, so a warranty claim is
  created the same way a return is.
- Read resources: `saleChannels()`, `returnPoints()`, `orderedProducts()`, `itemConditions()`,
  `itemReasons()`, `itemResolutions()`.
- `rmaRequests()` with the collection, the item, `create()`, fifteen single-request actions and
  five bulk operations, all as flat methods taking `$requestId` first. `bulkChangeStatus()`
  names its second parameter `$stateIdentifier` to match `changeStatus()`, so a named argument
  reads the same on both, even though the wire payload keeps the API's own `statusId` key.
- Readonly models with `fromArray()`, `toArray()` and `raw()`; unknown members of a response
  stay reachable through `raw()`, so a field added by the API does not break hydration.
- `ResourceCollection` for one page and `Paginator` for lazy iteration over every page,
  following the server's own next link. This API reports pagination in headers rather than in
  the body - `X-Total-Count` and `Link: rel="next"` - so the parser folds those into the same
  shape a Hydra envelope would have carried; `totalItems()` is therefore the size of the whole
  set, not of the page.
- Exception hierarchy behind the `RetJetException` marker interface, mapping HTTP statuses onto
  `AuthenticationException`, `AccessDeniedException`, `NotFoundException`,
  `ValidationException`, `RateLimitException`, `ServerException`, `ApiException`, plus
  `TransportException`, `MalformedResponseException` and `ConfigurationException`.
- RFC 7807 problem documents on every API exception, ignoring the `trace[]` member the dev
  environment appends.
- `method()` and `path()` on every `ApiException`, folded into `getMessage()` too - no query
  string, no host - so an error tracker can tell which call failed.
- `RetryMiddleware`, enabled by default with three retries; `withRetry(0)` removes it.
- `LoggingMiddleware`, enabled by `withLogger()`.
- `withMiddleware()` for custom transport decorators.
- `array $query` on `list()` and `iterate()` for every resource, passed straight through as
  extra query-string parameters merged with `page`; `AbstractResource::collection()` already
  accepted it, but no public method exposed a way to pass it.
- `Configuration::defaultUserAgent()` reads the installed package version from
  `Composer\InstalledVersions` at call time instead of a version frozen in source, so the
  `User-Agent` header names the release actually running.
- `RmaRequest::createdAtAsDateTime()`/`deadlineTsAsDateTime()` and
  `TimelineEntry::createdAtAsDateTime()`, converting the Unix-second `?int` properties to a UTC
  `DateTimeImmutable` for callers who do not want to do that conversion themselves.
- A "Testing your integration" section in the README: `Client` is `final readonly` on purpose,
  and the PSR-18 seam (`Client::create()`'s second argument, `ClientBuilder::withHttpClient()`)
  is how to substitute a fake backend instead.
- A "Compatibility" section in the README stating what the semver promise covers: `@internal`
  and `@experimental` members are excluded, models may gain properties, a `void` action may
  start returning a value, and `$query` keys are only as stable as the API's own (undocumented)
  support for them.

### Security

- The logger masks six credential-bearing headers and strips credentials embedded in a base
  URI from exception messages.
- Request and response bodies are never logged, with no option to enable them: an `RmaRequest`
  carries a customer's e-mail, postal address and bank account number.
- Absolute URLs that leave the configured base URI are refused rather than followed, because
  every request carries the API key and a Hydra `view.next` link is server-controlled data.

### Known limitations

- **A rejected key answers `401` without a problem document.** It comes back as `text/html`
  carrying the bare string `Authentication failed`, so `AuthenticationException::problem()`
  has no `detail()`. A request with no `Authorization` header at all is the other case and does
  carry one, so `detail()` being `null` says the key was refused rather than missing. A `500` with
  `detail: "Unable to exchange token"` means the same thing and can still occur when the token
  exchange service is unhealthy; the SDK does not rewrite it into an `AuthenticationException`.
- **Actions return `void`.** Their responses echo the payload just sent, so a fresh `get()` is
  needed to observe the new state.
- **Bulk operations do not report per-request results.** The response echoes the ID list, with
  no per-ID status.
- **`removeFollower()` cannot unfollow another user.** The endpoint accepts neither a body nor
  a query parameter, so the method takes only `$requestId` - there is nowhere to name another
  user, and a server ignoring an undeclared body would silently remove your own subscription.
- **`orderedProducts()` searches rather than lists.** The endpoint refuses a request carrying
  no criteria, so `list()` and `iterate()` answer `400` unless `$query` names one of
  `email` + `orderId`, `usernameAllegro` + `phone`, or `parcelTrackingCode` on its own. The spec
  declares those parameters without marking any required, because OpenAPI cannot express
  "one of these three combinations".
- **Writes are effectively never retried**, because almost every write in this API is a POST
  and replaying one after an ambiguous failure could duplicate it.
- **The `timeout` default is only enforced for a client the SDK builds itself.** PSR-18 offers
  no way to apply a timeout to a client somebody else constructed.
- `user` and `data` on a timeline entry are readable through `raw()` only. The spec declares
  both as arrays of string, which a live response contradicts, so typing them from the schema
  would have baked that error into the public API. `items[]`, `confirmations[]` and
  `attachments[]` on `RmaRequest` were in the same position until the spec started describing
  them correctly; they are typed now (`RmaRequestItem[]`, `RmaRequestFile[]`).

### Settled before this tag

Three questions this client was built around have since been answered, two of them by the API
documenting itself properly and one by measurement:

- **A `429` does carry `Retry-After`.** Verified against production, alongside
  `X-RateLimit-Limit`, `-Remaining` and `-Reset`; the spec now documents all four on every
  operation. `RetryMiddleware` honours `Retry-After`, so it waits the interval the server asks
  for rather than guessing.
- **The `Bearer ` prefix is not a discrepancy.** The security scheme is named `ApiKey` and its
  description spells the prefix out, which is what the SDK sends.
- **Action endpoints read the request id from the path.** Every action schema marks `requestId`
  as `readOnly`, so the copy the SDK also sends in the body is redundant rather than load-bearing.
  It is still sent, because a server that reads either one gets the same value.

The three endpoints that could not be exercised when those questions were written have since
been called against production with a real key. `GET /v1/sale-channels`, `GET .../followers` and
`GET .../timeline` answer `200`; `GET /v1/ordered-products` answers `400` until it is given
search criteria, which is the known limitation above rather than a fault.

### Not exercised

Every read operation has been called against production. **The nineteen write operations have
not**: creating a request, the fifteen single-request actions and the five bulk operations
change real returns, so they were left alone. Their paths and payload shapes match the document,
and nothing beyond that is claimed for them.

[Unreleased]: https://github.com/RetJet/returns-api-php-client/compare/v0.1.0-beta.1...HEAD
[0.1.0-beta.1]: https://github.com/RetJet/returns-api-php-client/releases/tag/v0.1.0-beta.1
