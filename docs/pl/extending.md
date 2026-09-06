# Rozszerzanie klienta

[English](../en/extending.md) · **Polski**

Dwa punkty rozszerzeń pokrywają prawie wszystko: nowy zasób dla endpointu, którego SDK jeszcze
nie opakowuje, oraz middleware dla zachowań dotyczących każdego żądania.

## Dodanie zasobu

`AbstractResource` daje cztery czasowniki HTTP i trzy helpery zamieniające odpowiedź na modele.
Podklasa deklaruje, jaki model hydratuje i gdzie leży jej kolekcja, a potem pisze po jednej
cienkiej metodzie na operację.

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

To cała klasa. Warto uszanować dwie zasady:

- **Ścieżki przepisuj dosłownie z dokumentu API.** Dziś ścieżka elementu to ścieżka jego
  kolekcji plus id (`/v1/sale-channels` oraz `/v1/sale-channels/{id}`), ale do 09.2026 elementy
  i akcje leżały pod osobnym członem w liczbie pojedynczej. Ścieżka wyprowadzona z konwencji,
  zamiast wypisana wprost, po cichu trafi w złe miejsce przy następnej takiej zmianie.
- **Pomiń `itemPath()`, gdy nie ma endpointu elementu.** Domyślna implementacja zwraca `null`, a
  `item()` kończy się wtedy czytelnym `ConfigurationException`, zamiast odpytywać URL, którego
  API nie serwuje. `OrderedProducts` robi dokładnie tak.

Instancję tworzy się z transportem klienta:

```php
$warehouses = new App\RetJet\Warehouses($client->transport());

foreach ($warehouses->iterate() as $warehouse) {
    // ...
}
```

Wewnątrz samego SDK zasób dostaje dodatkowo akcesor na `Client`:

```php
public function warehouses(): Warehouses
{
    return new Warehouses($this->transport);
}
```

### Model

Modele to obiekty wartości `readonly`. Każda właściwość jest nullowalna, bo żaden schemat w tym
API nie deklaruje pola wymaganego, a `raw()` trzyma nietknięty payload, więc pole dodane później
wciąż jest dostępne:

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

Każdą wartość zawężaj, zamiast rzutować: pole, którego brakuje albo które trzyma wartość
nieoczekiwanego typu, ma stać się `null` - dzięki temu zmiana schematu po stronie API degraduje
się łagodnie, a nie do bzdury. Modele samego SDK są zbudowane dokładnie tak.

### Payloady zapisu

Ciało żądania implementuje `Payload`. Jego `toArray()` pomija pola nieustawione - pole
nieustawione i jawny `null` to dla serwera dwie różne informacje:

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

## Dodanie middleware

Middleware to `Transport`, który opakowuje inny `Transport`. Nic poza tym: zaimplementuj
`MiddlewareInterface`, przyjmij następny transport jako pierwszy argument konstruktora i
deleguj.

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

Rejestruje się go przez `withMiddleware()`. **Przyjmuje fabrykę, nie instancję**, bo dekorowany
transport nie istnieje przed wykonaniem `build()`:

```php
use RetJetApi\Returns\Client;
use RetJetApi\Returns\Http\Transport;

$client = Client::builder()
    ->withApiKey($key)
    ->withMiddleware(fn (Transport $next): Transport => new CacheMiddleware($next, $pool))
    ->build();
```

### Kolejność stosu

Stos budowany jest od środka na zewnątrz:

```text
PsrTransport -> LoggingMiddleware -> RetryMiddleware -> your middleware
```

- `LoggingMiddleware` siedzi **wewnątrz** ponawiania, więc każda próba daje własny rekord, a
  burza ponowień jest w logu widoczna, zamiast zwinąć się do jednej linii.
- Twoje middleware trafia **najbardziej na zewnątrz**, a każda kolejna rejestracja opakowuje
  poprzednią, więc ostatnia dodana widzi wywołanie jako pierwsza. Tego właśnie potrzebuje
  middleware zwierające obieg, takie jak powyższy cache: odpowiada, nie budząc pozostałych dwóch.

Ponieważ błędy przychodzą jako wyjątki, a nie jako kody statusu, middleware reagujące na awarie
łapie je:

```php
use RetJetApi\Returns\Exception\RetJetException;

try {
    return $this->next->request($method, $path, $query, $body, $headers);
} catch (RetJetException $exception) {
    $this->metrics->increment('retjet.failure', ['exception' => $exception::class]);

    throw $exception;
}
```

Pisząc własne middleware, pamiętaj o dwóch rzeczach:

- **Nie loguj i nie utrwalaj ciał.** `RmaRequest` niesie e-mail, adres pocztowy i numer konta
  bankowego klienta.
- **Nie ponawiaj `POST` na własną rękę.** SDK celowo tego nie robi, bo powtórzenie po
  niejednoznacznej awarii może zdublować zapis.

## Wywołanie endpointu bez zasobu

Do jednorazowego użycia wystarczy sam transport. Zwraca zdekodowane ciało i rzuca zwykłymi
wyjątkami SDK:

```php
$payload = $client->transport()->request('GET', '/v1/some-new-endpoint', ['page' => 1]);
```

Absolutne URL-e też są akceptowane, ale wyłącznie wtedy, gdy wskazują skonfigurowany bazowy URI:
każde żądanie niesie klucz API, więc podążenie za linkiem od serwera na inny host ujawniłoby go.
Wszystko inne kończy się `ConfigurationException`.
