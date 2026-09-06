# Extending the client

**English** · [Polski](../pl/extending.md)

Two extension points cover almost everything: a new resource for an endpoint the SDK does not
wrap yet, and a middleware for behaviour that applies to every request.

## Adding a resource

`AbstractResource` supplies the four HTTP verbs and the three helpers that turn a response into
models. A subclass declares which model it hydrates and where its collection lives, then writes
one thin method per operation.

```php
namespace App\RetJet;

use Generator;
use RetJetApi\Returns\Collection\ResourceCollection;
use RetJetApi\Returns\Resource\AbstractResource;

/**
 * @extends AbstractResource<Warehouse>
 */
final class Warehouses extends AbstractResource
{
    /** @return ResourceCollection<Warehouse> */
    public function list(int $page = 1, array $query = []): ResourceCollection
    {
        return $this->collection($page, $query);
    }

    /** @return Generator<int, Warehouse> */
    public function iterate(array $query = []): Generator
    {
        return $this->paginate($query)->getIterator();
    }

    public function get(int $id): Warehouse
    {
        return $this->item($id);
    }

    protected function model(): string
    {
        return Warehouse::class;
    }

    protected function collectionPath(): string
    {
        return '/v1/warehouses';
    }

    protected function itemPath(): string
    {
        return '/v1/warehouse/{id}';
    }
}
```

That is the whole class. Two rules are worth respecting:

- **Copy the paths verbatim from the API document.** An item path is its collection path plus
  an id today (`/v1/sale-channels` and `/v1/sale-channels/{id}`), but items and actions sat
  under a separate singular segment until 2026-09. A path derived from a convention rather than
  written out goes silently to the wrong place the next time that changes.
- **Leave `itemPath()` out when there is no item endpoint.** The default returns `null` and
  `item()` then fails with a clear `ConfigurationException` instead of requesting a URL the API
  does not serve. `OrderedProducts` does exactly this.

Instantiate it with the client's transport:

```php
$warehouses = new App\RetJet\Warehouses($client->transport());

foreach ($warehouses->iterate() as $warehouse) {
    // ...
}
```

Inside the SDK itself, the resource also gets an accessor on `Client`:

```php
public function warehouses(): Warehouses
{
    return new Warehouses($this->transport);
}
```

### The model

Models are readonly value objects. Every property is nullable, because no schema in this API
declares a required member, and `raw()` keeps the untouched payload so a field added later is
still reachable:

```php
namespace App\RetJet;

use RetJetApi\Returns\Model\Model;

final readonly class Warehouse implements Model
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public ?int $id = null,
        public ?string $name = null,
        private array $raw = [],
    ) {
    }

    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        $name = $data['name'] ?? null;

        return new self(
            is_int($id) ? $id : null,
            is_string($name) ? $name : null,
            $data,
        );
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }

    public function raw(): array
    {
        return $this->raw;
    }
}
```

Narrow every value instead of casting it: a member that is missing or holds an unexpected type
has to become `null`, so a schema change upstream degrades gracefully rather than producing
nonsense. The SDK's own models are built exactly this way.

### Write payloads

A request body implements `Payload`. Its `toArray()` omits members left unset - an unset member
and an explicit `null` say different things to the server:

```php
namespace App\RetJet;

use RetJetApi\Returns\Request\Payload;

final readonly class CreateWarehouse implements Payload
{
    public function __construct(
        public ?string $name = null,
        public ?string $country = null,
    ) {
    }

    public function toArray(): array
    {
        return array_filter(
            ['name' => $this->name, 'country' => $this->country],
            static fn (mixed $value): bool => $value !== null,
        );
    }
}
```

## Adding middleware

A middleware is a `Transport` that wraps another `Transport`. There is nothing else to it:
implement `MiddlewareInterface`, take the next transport as the first constructor argument, and
delegate.

```php
namespace App\RetJet;

use Psr\Cache\CacheItemPoolInterface;
use RetJetApi\Returns\Http\Middleware\MiddlewareInterface;
use RetJetApi\Returns\Http\Transport;

final class CacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Transport $next,
        private readonly CacheItemPoolInterface $pool,
    ) {
    }

    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        array $headers = [],
    ): array {
        if (strtoupper($method) !== 'GET') {
            return $this->next->request($method, $path, $query, $body, $headers);
        }

        $item = $this->pool->getItem(hash('xxh128', $path . serialize($query)));

        if ($item->isHit()) {
            $cached = $item->get();

            if (is_array($cached)) {
                return $cached;
            }
        }

        $payload = $this->next->request($method, $path, $query, $body, $headers);

        $this->pool->save($item->set($payload)->expiresAfter(3600));

        return $payload;
    }
}
```

Register it with `withMiddleware()`. **It takes a factory, not an instance**, because the
transport being decorated does not exist until `build()` runs:

```php
use RetJetApi\Returns\Client;
use RetJetApi\Returns\Http\Transport;

$client = Client::builder()
    ->withApiKey($key)
    ->withMiddleware(fn (Transport $next): Transport => new CacheMiddleware($next, $pool))
    ->build();
```

### Stack order

The stack is built inside out:

```text
PsrTransport -> LoggingMiddleware -> RetryMiddleware -> your middleware
```

- `LoggingMiddleware` sits **inside** retrying, so every attempt produces its own record and a
  retry storm is visible in the log rather than collapsed into one line.
- Your middleware goes **outermost**, and each registration wraps the previous one, so the last
  one added sees a call first. That is what a short-circuiting middleware such as the cache
  above needs: it answers without waking either of the other two.

Because errors arrive as exceptions rather than as status codes, a middleware that reacts to
failures catches them:

```php
use RetJetApi\Returns\Exception\RetJetException;

try {
    return $this->next->request($method, $path, $query, $body, $headers);
} catch (RetJetException $exception) {
    $this->metrics->increment('retjet.failure', ['exception' => $exception::class]);

    throw $exception;
}
```

Keep two things in mind when writing one:

- **Do not log or persist bodies.** An `RmaRequest` carries a customer's e-mail, postal address
  and bank account number.
- **Do not retry a `POST` on your own.** The SDK deliberately does not, because a replay after
  an ambiguous failure can duplicate a write.

## Calling an endpoint with no resource at all

For a one-off, use the transport directly. It returns the decoded body and throws the usual SDK
exceptions:

```php
$payload = $client->transport()->request('GET', '/v1/some-new-endpoint', ['page' => 1]);
```

Absolute URLs are accepted too, but only when they point at the configured base URI: every
request carries the API key, so following a server-supplied link to another host would disclose
it. Anything else raises a `ConfigurationException`.
