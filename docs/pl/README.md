# returns-api-php-client

[English](../../README.md) · **Polski**

[![CI](https://github.com/RetJet/returns-api-php-client/actions/workflows/ci.yml/badge.svg)](https://github.com/RetJet/returns-api-php-client/actions/workflows/ci.yml)

Klient PHP dla **RetJet API** - biblioteka do obsługi zwrotów i reklamacji gwarancyjnych,
wpinalna w dowolny projekt PHP, w tym Symfony i Laravel. Bez zależności frameworkowych:
korzysta z dowolnego klienta HTTP zgodnego z PSR-18, który już masz w projekcie.

> **Status:** przed `v1.0.0`. Pakiet pokrywa całość dokumentu OpenAPI w postaci, w jakiej
> serwuje go produkcja, ale jego własne publiczne API może się jeszcze zmienić w wydaniu minor -
> patrz [Zgodność](#zgodność) i [CHANGELOG.md](CHANGELOG.md).

## Instalacja

```bash
composer require retjet/returns-api-php-client
```

Wymagania: PHP `^8.2` oraz dowolna implementacja PSR-18 / PSR-17, wykrywana automatycznie
przez `php-http/discovery`. Jeśli w projekcie nie ma żadnej, dodaj ją:

```bash
composer require guzzlehttp/guzzle
# or: composer require symfony/http-client nyholm/psr7
```

Ten SDK sam nie wydaje kluczy API - tylko woła RetJet API takim, który już masz. Jeśli
jeszcze go nie masz, poproś o dostęp na [retjet.com](https://retjet.com).

## Szybki start

```php
use RetJetApi\Returns\Client;

$client = Client::create('YOUR_API_KEY');

$rma = $client->rmaRequests()->get(1234);

echo $rma->identifier;        // RMA-2024-001234
echo $rma->state?->label;     // in_progress
echo $rma->customer?->email;  // john.doe@example.com
```

Klucz API to jedyna wymagana rzecz, wszystko inne ma wartość domyślną. Cały SDK jest
dostępny z tego jednego `use` - nie ma osobnej klasy fasady.

## Konfiguracja

`Client::create()` to skrót do buildera z domyślnymi ustawieniami. `Client::builder()`
przydaje się, gdy trzeba któreś z nich nadpisać:

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

| Ustawienie | Default | Kiedy nadpisywać |
|---|---|---|
| `baseUri` | `https://api.retjet.com` | testy, środowiska staging/custom, własny proxy |
| `maxRetries` | `3` | `withRetry(0)` wyłącza ponawianie całkowicie |
| `timeout` | **`10` s - egzekwowane dla klienta, którego SDK buduje sam** (minimum `1`) | wolne łącza, duże załączniki |
| klient HTTP | wybierany przez `php-http/discovery` | gdy w projekcie jest kilka implementacji PSR-18 |
| logger | brak | debug, audyt |
| user agent | `retjet-returns-api-php-client/<zainstalowana wersja>` | gdy w nagłówku ma być własna aplikacja |

Zastrzeżenie przy `timeout` jest celowe. PSR-18 nie zna pojęcia timeoutu, więc SDK może go
zastosować wyłącznie do klienta, którego sam konstruuje - umie skonfigurować
`symfony/http-client`, `guzzlehttp/guzzle` (7 i nowszy) oraz `php-http/curl-client`. Kiedy
`php-http/discovery` znajdzie inną implementację, **domyślne 10 s nie jest egzekwowane** i
obowiązują wartości domyślne tego klienta. Jawne `withTimeout()` w takiej sytuacji kończy się
głośnym `ConfigurationException`, a nie cichym brakiem efektu.

### Timeout a własny klient HTTP

PSR-18 nie zna pojęcia timeoutu: `sendRequest()` blokuje i nie da się go przerwać z zewnątrz,
więc timeout można ustawić wyłącznie w momencie konstruowania klienta.

**`withHttpClient()` i `withTimeout()` wykluczają się nawzajem** - połączenie ich rzuca
`ConfigurationException`, zamiast przyjąć timeout, którego i tak nie dałoby się dotrzymać:

```php
// Let the SDK build the client, and it will honour the timeout.
$client = Client::builder()->withApiKey($key)->withTimeout(30)->build();

// Bring your own client, and the timeout is yours to configure on it.
$client = Client::builder()
    ->withApiKey($key)
    ->withHttpClient(new GuzzleHttp\Client(['timeout' => 30]))
    ->build();
```

## Endpointy

Pokryte są wszystkie 34 operacje z dokumentu OpenAPI plus jeden endpoint, który istnieje
wyłącznie w dokumentacji Hydra. Nazewnictwo jest regularne: kolekcja, jej elementy i każda akcja
dzielą jeden człon w liczbie mnogiej (`/v1/sale-channels` oraz `/v1/sale-channels/{id}`). Nie
zawsze tak było - do 09.2026 elementy i akcje leżały pod członem w liczbie pojedynczej - więc
klient napisany pod starszy opis tego API trafia w adresy zwracające dziś 404.

### Żądania RMA

| Operacja | Metoda |
|---|---|
| `GET /v1/rma-requests` | `$client->rmaRequests()->list(page: 1)` · `iterate()` |
| `GET /v1/rma-requests/{id}` | `$client->rmaRequests()->get($id)` |
| `POST /v1/rma-requests` | `$client->rmaRequests()->create(new CreateRmaRequest(...))` |

### Akcje na żądaniu RMA

Każda akcja przyjmuje `$requestId` jako pierwszy argument. Nie ma obiektu pośredniego, więc
`$client->rmaRequests()->` pokazuje cały zasób w jednym miejscu.

| Operacja | Metoda |
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

### Operacje bulk

| Operacja | Metoda |
|---|---|
| `POST /v1/rma-requests/bulk/owner` | `bulkAssignOwner($requestIds, $userId)` |
| `POST /v1/rma-requests/bulk/unassign-owner` | `bulkUnassignOwner($requestIds)` |
| `POST /v1/rma-requests/bulk/star` | `bulkStar($requestIds)` |
| `POST /v1/rma-requests/bulk/unstar` | `bulkUnstar($requestIds)` |
| `POST /v1/rma-requests/bulk/status` | `bulkChangeStatus($requestIds, $stateIdentifier)` |

### Dane słownikowe

| Operacja | Metoda |
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

`orderedProducts()` celowo nie ma `get()`: API nie publikuje operacji
`/v1/ordered-product/{id}`.

## Kolekcje i paginacja

Dwie metody, bo to dwie różne potrzeby:

```php
// One page. Use it when the page number comes from outside - a paginated UI, a job argument.
$page = $client->rmaRequests()->list(page: 2);

$page->totalItems();   // size of the whole result set
count($page);          // size of this page - not the same number
$page->hasNextPage();
$page->nextPage();     // the server's next-page URL, or null on the last page

foreach ($page as $rma) {
    // ...
}

// Every page, lazily. Nothing is fetched until the iteration starts, and page N+1 is only
// requested once page N has been consumed - so this is safe for result sets larger than memory.
foreach ($client->rmaRequests()->iterate() as $rma) {
    // ...
}
```

`iterate()` celowo nie nazywa się `all()`: wykonuje jedno żądanie HTTP na stronę i nigdy nie
trzyma całego zbioru wyników w pamięci.

Podkolekcje działają tak samo:

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

Kolekcje są pobierane z nagłówkiem `Accept: application/ld+json`. To nie jest kosmetyka:
zwykła reprezentacja JSON to goła tablica bez `totalItems` i bez linków `view`, co czyni
paginację niemożliwą.

API nie przyjmuje parametru rozmiaru strony - `page` jest jedynym, jaki akceptuje. Przy
ostatnim pomiarze na żywym środowisku strona mieściła **30 rekordów**, ale nic w API tego nie
gwarantuje, więc czytaj `count($page)` zamiast zakładać tę liczbę.

`list(int $page = 1, array $query = [])` i `iterate(array $query = [])` przekazują `$query`
wprost jako dodatkowe parametry query-string, łączone z `page`. Dokument OpenAPI nie deklaruje
żadnych parametrów filtrowania, więc to, które klucze API faktycznie honoruje, jest
niepotwierdzone - `$query` to furtka na to, co ostatecznie wspiera szablon `search` z Hydry, a
nie udokumentowane API filtrowania. `page` wewnątrz `$query` jest nadpisywane przez argument
`$page`, więc do wyboru strony używaj argumentu, a nie `$query['page']`.

### Obiekty zagnieżdżone

`RmaRequest::$items`, `$confirmations`, `$attachments` i `$saleChannel` to typowane obiekty
zagnieżdżone - odpowiednio `RmaRequestItem[]`, `RmaRequestFile[]` i `SaleChannel`:

```php
$rma = $client->rmaRequests()->get(1234);

foreach ($rma->items ?? [] as $item) {
    echo $item->requestedQty;
    echo $item->orderedProduct?->name;
}

echo $rma->saleChannel?->label;
```

Zostają `null`, gdy API całkowicie pomija dany człon - to co innego niż „nie ma żadnych", co
API wyraża pustą listą. Wszystko, czego SDK jeszcze nie typuje - łącznie z członami JSON-LD
i polami, które API doda po tym wydaniu - pozostaje dostępne przez `raw()`, które zwraca cały
zdekodowany payload nietknięty.

## Zapisy i akcje

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

`saleChannelId`, `customer` i `items` są wymagane - API odrzuci wywołanie create bez nich.
Poza tym obiekty payloadu wysyłają wyłącznie pola, które ustawisz - pole nieustawione jest
pomijane, a nie wysyłane jako jawny `null`, bo to dwie różne informacje dla serwera. Cokolwiek,
czego SDK jeszcze nie nazywa, można przekazać przez `extra`:

```php
new CreateRmaRequest(
    saleChannelId: 7,
    customer: new CreateRmaRequestCustomer(/* ... */),
    items: [/* ... */],
    extra: ['fieldAddedByTheApiLater' => 'value'],
);
```

### Akcje zwracają void

Każdy endpoint akcji odpowiada echem wysłanego payloadu albo kodem `204`. Żaden nie zwraca
zaktualizowanego żądania, więc **po akcji potrzebny jest osobny `get()`, żeby zobaczyć nowy
stan**:

```php
$client->rmaRequests()->changeStatus(1234, 'in_progress');

$updated = $client->rmaRequests()->get(1234);   // the only way to observe the new state

echo $updated->state?->label;
```

Sukces jest komunikowany brakiem wyjątku. Rozszerzenie metody `void` o zwracanie wartości nie
jest zmianą łamiącą, więc jeśli API zacznie kiedyś zwracać coś użytecznego, da się to dodać
bez rewolucji.

### Operacje bulk nie raportują wyniku per żądanie

Endpointy bulk odpowiadają echem listy ID, którą dostały. W odpowiedzi nie ma statusu per ID,
więc **nie dowiesz się, które ID się powiodły, a które zawiodły** - SDK nie ma jak wymyślić tej
informacji:

```php
$client->rmaRequests()->bulkChangeStatus([1, 2, 3], 'closed');
// Returns void. If ID 2 did not exist, nothing here will say so.
```

Kiedy wynik pojedynczego żądania ma znaczenie, użyj pętli po akcjach jednostkowych albo
odczytaj zmienione żądania ponownie. ID niebędące liczbami całkowitymi są odrzucane lokalnie
przez `ConfigurationException`, a nie rzutowane - ciche `(int) '12a'` trafiłoby w żądanie 12,
czyli w istniejący i niepowiązany rekord.

### removeFollower nie odsubskrybuje innego użytkownika

`DELETE /v1/rma-requests/{requestId}/follower` nie przyjmuje ani body, ani parametru query, więc
jedynym obserwatorem, którego może usunąć, jest zalogowany użytkownik. Właśnie dlatego
`removeFollower()` przyjmuje wyłącznie `$requestId` - nie ma gdzie wysłać id innego użytkownika,
a serwer ignorujący niezadeklarowane body po cichu skasowałby własną obserwację wywołującego:

```php
$client->rmaRequests()->removeFollower(1234);   // unfollows the authenticated user
```

## Obsługa błędów

Każdy wyjątek tej biblioteki implementuje `RetJetException`, więc jeden `catch` obejmuje cały
SDK:

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

### Mapowanie statusów na wyjątki

| Sytuacja | Wyjątek |
|---|---|
| `400 Bad Request` | `ApiException` |
| `401 Unauthorized` | `AuthenticationException` |
| `403 Forbidden` | `AccessDeniedException` |
| `404 Not Found` | `NotFoundException` |
| `422 Unprocessable Entity` | `ValidationException` |
| `429 Too Many Requests` | `RateLimitException` |
| dowolny inny `4xx` | `ApiException` |
| dowolny `5xx` | `ServerException` |
| błąd sieci, brak odpowiedzi | `TransportException` |
| status sukcesu, nieużywalne ciało | `MalformedResponseException` |
| błąd konfiguracji po stronie klienta | `ConfigurationException` |

`400` jest wymieniony osobno obok `422`, bo to udokumentowana odpowiedź tego API, a nie
przypadek teoretyczny: `POST /v1/rma-requests` deklaruje oba. Źle zbudowane żądanie daje `400`
i zwykły `ApiException`, a poprawnie zbudowane, które nie przejdzie walidacji, daje `422` i
`ValidationException` z `violations()`. Łapanie samego `ValidationException` przeoczy więc
realne błędy wejścia.

Każdy `ApiException` niesie dokument problemu RFC 7807 z ciała odpowiedzi, a do tego metodę i
ścieżkę żądania, które padło - bez query stringa i bez hosta, więc nic, czego narzędzie do
śledzenia błędów nie powinno widzieć:

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

`RateLimitException::retryAfter()` zwraca wartość `Retry-After` w sekundach, obsługując obie
formy dopuszczone przez nagłówek: liczbową i datę HTTP.

### Błąd uwierzytelnienia nie niesie szczegółów

Nieprawidłowy, cofnięty albo brakujący klucz API daje `401` / `AuthenticationException`, czyli
to, czego się spodziewasz. Dwie rzeczy warto wiedzieć, zanim zaczniesz to debugować.

**Ciało odpowiedzi to `text/html`, nie `application/problem+json`.** Każdy inny status błędu
w tym API odpowiada dokumentem problemu; `401` odpowiada gołym napisem `Authentication failed`.
SDK obsługuje to bez awarii, ale nie ma czego odczytać:

```php
use RetJetApi\Returns\Exception\AuthenticationException;

try {
    $client->rmaRequests()->list();
} catch (AuthenticationException $e) {
    $e->status();             // 401
    $e->problem()->detail();  // null - the server sent no problem document
}
```

**`500` z `detail: "Unable to exchange token"` znaczy to samo.** Serwer wymienia Twój klucz na
JWT przy każdym żądaniu; gdy ta wymiana zawiedzie, dostajesz błąd serwera zamiast `401`. Do
sierpnia 2026 była to normalna odpowiedź na zły klucz i nadal może się pojawić, jeśli sama
usługa wymiany nie działa - traktuj to jako problem z uwierzytelnieniem, nie jako awarię:

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

SDK celowo nie przepisuje tego `500` na `AuthenticationException`: oznaczałoby to dopasowywanie
po treści komunikatu, czyli zgadywanie, które przestaje działać w chwili zmiany sformułowania.

## Ponawianie

`RetryMiddleware` jest w stosie transportu domyślnie, z trzema ponowieniami. `withRetry(0)`
usuwa go całkowicie.

To, co jest ponawiane, zależy od metody HTTP - i to jest tu najważniejsze:

| Awaria | `GET`, `HEAD`, `PUT`, `DELETE`, `OPTIONS`, `TRACE` | `POST`, `PATCH` |
|---|---|---|
| `429` rate limit | ponawiane | **ponawiane** |
| `5xx` błąd serwera | ponawiane | nieponawiane |
| błąd sieci | ponawiane | nieponawiane |
| cokolwiek innego | nieponawiane | nieponawiane |

`429` jest zwracany, zanim żądanie dotrze do handlera, więc nic nie zostało przetworzone i
powtórzenie jest bezpieczne niezależnie od metody.

`5xx` jest odwrotnością: serwer przyjął żądanie i dopiero potem gdzieś się wywrócił. `500` z
`POST .../message` może równie dobrze znaczyć, że wiadomość powstała, a wysypała się dopiero
odpowiedź. Błąd sieci jest jeszcze gorszy, bo PSR-18 spłaszcza *„connection refused"* -
dowodliwie bezpieczne do powtórzenia - i *„read timeout po tym, jak serwer już zadziałał"* -
zupełnie niebezpieczne - do tego samego wyjątku, a rozróżnić ich nie da się bez zaglądania w
bebechy konkretnego klienta.

**Praktyczna konsekwencja jest taka, że zapisy w tym API są w zasadzie nigdy nieponawiane, bo
prawie każdy zapis to POST. To jest zamierzone, a nie luka.** Zdublowane żądanie RMA albo
podwójnie zastosowana zmiana statusu to gorsza awaria niż błąd, który wywołujący widzi i może
obsłużyć.

Odmierzanie czasu to backoff wykładniczy od 1 s, podwajany z każdą próbą (1 s, 2 s, 4 s). `429`
z nagłówkiem `Retry-After` używa liczby podanej przez serwer. Proces wywołujący chronią dwa
sufity: pojedyncze oczekiwanie nie przekracza 60 s, a suma oczekiwań w obrębie jednego
wywołania również 60 s; po przekroczeniu któregokolwiek wyjątek leci dalej z nietkniętym
`retryAfter()`, żeby dało się zaplanować pracę, zamiast blokować workera.

## Logowanie

`withLogger()` wstawia do stosu `LoggingMiddleware`; bez tego nic nie jest logowane. Zapisywana
jest każda próba, więc burza ponowień jest w logu widoczna, a nie zwinięta do jednej linii.

Dwie rzeczy nie trafiają do loga nigdy:

- **Poświadczenia.** Maskowanych jest sześć nazw nagłówków (`Authorization`,
  `Proxy-Authorization`, `Cookie`, `Set-Cookie`, `X-Api-Key`, `X-Auth-Token`), a poświadczenia
  osadzone w bazowym URI (`https://user:pass@host`) są usuwane z komunikatów wyjątków, zanim
  zostaną zapisane.
- **Ciała żądań i odpowiedzi - bez możliwości ich włączenia.** `RmaRequest` niesie e-mail,
  adres pocztowy i `refundBankAccountNo` klienta, a payload `CreateRmaRequest` to samo.
  Skopiowanie tego do loga debugowego przenosi dane osobowe i bankowe do systemu o innej
  retencji i innych prawach dostępu niż API, zwykle bez czyjejkolwiek decyzji. Zapisywane są
  metoda, ścieżka, query, zamaskowane nagłówki, czas trwania i wynik.

## Testowanie własnej integracji

`Client` jest `final readonly`, więc nie da się go zmockować frameworkiem do mockowania - to
świadoma decyzja, nie przeoczenie. Szwem jest klient PSR-18 pod spodem: zarówno
`Client::create()`, jak i `ClientBuilder::withHttpClient()` przyjmują dowolny
`Psr\Http\Client\ClientInterface`, więc ręcznie napisany stub (albo `php-http/mock-client`,
albo prawdziwy klient wskazujący na lokalny serwer z fixture'ami) zastępuje sieć, nie ruszając
samego `Client`:

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

Samego `Client` nie trzeba mockować: wszystko, co robi - hydratacja, paginacja, ponowienia,
mapowanie wyjątków - działa na odpowiedzi, którą odda twój stub.

## Zgodność

Ten projekt trzyma się [Semantic Versioning](https://semver.org/); przed `v1.0.0` zmiany
łamiące mogą trafić nawet do wydania minor (patrz uwaga o statusie wyżej). Co obejmuje ta
obietnica, gdy już zostanie otagowana:

- **Objęte:** publiczne klasy w `src/`, ich publiczne metody i konstruktory, oraz hierarchia
  wyjątków.
- **Nieobjęte:** wszystko oznaczone `@internal` (dziś `Model\Hydration`, `Http\Redact`,
  `Http\RequestBuilder`, `Http\ResponseParser`, `Http\HttpClientFactory` - patrz
  [docs/pl/api-reference.md](https://github.com/RetJet/returns-api-php-client/blob/main/docs/pl/api-reference.md#poza-publicznym-api))
  oraz wszystko oznaczone `@experimental` (dziś nic nie nosi tego znacznika).
- **Modele mogą zyskiwać nowe właściwości w wydaniu minor.** Pole dodane przez API albo zostaje
  otypowane później, albo pozostaje dostępne przez `raw()`; żadne z tych zachowań nie psuje
  kodu, który nie polega na nieobecności nowego pola.
- **Metoda akcji zwracająca dziś `void` może zacząć zwracać wartość.** Odpowiedź każdej akcji
  to dziś echo payloadu albo `204`, więc nic dziś nie jest odrzucane, a poszerzenie `void` do
  realnego typu zwracanego jest zmianą addytywną, nie łamiącą.
- **`array $query` na `list()`/`iterate()` nie jest walidowanym API filtrowania.** To, które
  klucze faktycznie honoruje serwer, zależy od API, nie od SDK, i może się zmieniać niezależnie
  od wersji tego pakietu.

Wszystko inne - sygnatury metod, kolejność parametrów konstruktora, round-tripping
`toArray()`/`fromArray()` - trzyma się zwykłego semver.

## Dokumentacja

- [Referencja API](https://github.com/RetJet/returns-api-php-client/blob/main/docs/pl/api-reference.md) - wszystkie publiczne klasy i metody, razem z endpointami
- [Wpięcie w Symfony](https://github.com/RetJet/returns-api-php-client/blob/main/docs/pl/symfony.md)
- [Wpięcie w Laravel](https://github.com/RetJet/returns-api-php-client/blob/main/docs/pl/laravel.md)
- [Rozszerzanie klienta](https://github.com/RetJet/returns-api-php-client/blob/main/docs/pl/extending.md)
- [Współtworzenie](https://github.com/RetJet/returns-api-php-client/blob/main/docs/pl/CONTRIBUTING.md)
- [Polityka bezpieczeństwa](SECURITY.md)
- [Changelog](CHANGELOG.md)

## Licencja

MIT - patrz [LICENSE](../../LICENSE).
