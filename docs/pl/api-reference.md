# Referencja API

[English](../en/api-reference.md) · **Polski**

Wszystkie publiczne klasy i metody SDK w jednym miejscu. [README](README.md) tłumaczy,
po co sięgać w danej sytuacji; ta strona jest wyczerpującą listą - łącznie z tym, co nie ma
za sobą żadnego endpointu: kolekcjami, modelami, payloadami, wyjątkami i stosem transportu.

Cokolwiek nie jest tu wymienione, jest wewnętrzne i może się zmienić bez podbicia wersji major.

## Punkt wejścia

### Client

`RetJetApi\Returns\Client` - `final readonly`. Trzyma transport i konfigurację, wydaje osiem
zasobów.

| Człon | Sygnatura | Uwagi |
|---|---|---|
| `create()` | `static create(string $apiKey, ?ClientInterface $httpClient = null): self` | Cała konfiguracja, jakiej potrzebuje większość wywołujących. Opcjonalny klient PSR-18 jest dla kontenerów, które już go mają; bez niego SDK zbuduje własny, honorujący `Configuration::$timeout` |
| `builder()` | `static builder(): ClientBuilder` | Konfiguracja fluent, na wszystko, czego `create()` nie obejmuje |
| `__construct()` | `__construct(Transport $transport, Configuration $configuration, ?LoggerInterface $logger = null)` | Konstrukcja wprost, dla kontenera DI składającego stos samodzielnie |
| `configuration()` | `configuration(): Configuration` | Rozstrzygnięte ustawienia, razem z domyślnymi |
| `transport()` | `transport(): Transport` | Najbardziej zewnętrzny dekorator stosu |
| `logger()` | `logger(): ?LoggerInterface` | `null`, dopóki nie wywołano `withLogger()` |

Akcesory zasobów - po jednym na zasób, żaden nie jest memoizowany (zasoby są bezstanowe):

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

`RetJetApi\Returns\ClientBuilder` - każda metoda zwraca `self`, więc się łańcuchują. Wymagane
jest wyłącznie `withApiKey()`.

| Metoda | Sygnatura | Domyślnie, gdy pominięta |
|---|---|---|
| `withApiKey()` | `withApiKey(string $apiKey): self` | **wymagane** - bez tego `build()` rzuca `ConfigurationException` |
| `withBaseUri()` | `withBaseUri(string $baseUri): self` | `https://api.retjet.com`; końcowy ukośnik jest obcinany |
| `withHttpClient()` | `withHttpClient(ClientInterface $httpClient): self` | rozstrzygane przez `php-http/discovery` |
| `withTimeout()` | `withTimeout(int $seconds): self` | `10`, minimum `1`; **wzajemnie wykluczające się z `withHttpClient()`** |
| `withRetry()` | `withRetry(int $maxRetries): self` | `3`; `withRetry(0)` wypina `RetryMiddleware` całkowicie |
| `withLogger()` | `withLogger(LoggerInterface $logger): self` | brak jakiegokolwiek logowania |
| `withUserAgent()` | `withUserAgent(string $userAgent): self` | `Configuration::defaultUserAgent()` |
| `withMiddleware()` | `withMiddleware(callable $factory): self` | brak; przyjmuje fabrykę `callable(Transport): Transport`, nie instancję - dekorowany transport nie istnieje przed `build()` |
| `build()` | `build(): Client` | - |

`withRetry(5)` znaczy **sześć żądań**: jedna próba plus pięć ponowień.

### Configuration

`RetJetApi\Returns\Configuration` - `final readonly`. Waliduje w konstruktorze, więc nieużywalne
ustawienie wysypuje się przy budowaniu, a nie na pierwszym żądaniu.

| Stała | Wartość |
|---|---|
| `DEFAULT_BASE_URI` | `https://api.retjet.com` |
| `DEFAULT_TIMEOUT` | `10` |
| `DEFAULT_MAX_RETRIES` | `3` |

`defaultUserAgent(): string` to metoda statyczna, nie stała: odczytuje zainstalowaną wersję
pakietu z `Composer\InstalledVersions` w momencie wywołania
(`retjet-returns-api-php-client/<wersja> (+https://github.com/RetJet/returns-api-php-client)`),
a gdy wersji nie da się ustalić, wraca do `dev`. Człon produktu to nazwa pakietu Composera
z ukośnikiem zamienionym na myślnik, więc przemianowanie pakietu przemianowuje też nagłówek. Tego właśnie używa `Configuration` zbudowana bez jawnego `$userAgent`.

| Właściwość | Typ | Odrzucana, gdy |
|---|---|---|
| `$apiKey` | `string` | pusty → `ConfigurationException::missingApiKey()` |
| `$baseUri` | `string` | nie jest bezwzględnym URI http(s) → `invalidBaseUri()`; końcowy ukośnik jest normalizowany |
| `$timeout` | `int` | `< 1` → `invalidTimeout()`; `0` jest odrzucane, bo Guzzle i cURL czytają je jako „czekaj w nieskończoność” |
| `$maxRetries` | `int` | `< 0` → `negativeMaxRetries()` |
| `$userAgent` | `string` | - |

## Zasoby

Wszystkie osiem rozszerza `AbstractResource` i ma ten sam kształt: `list(page: N)` na jedną
stronę, `iterate()` na wszystkie leniwie, `get($id)` tam, gdzie API publikuje endpoint itemu.

### RmaRequests - kolekcja i item

`RetJetApi\Returns\Resource\RmaRequests`. Item (`/v1/rma-requests/{id}`) i każda akcja niżej
(`/v1/rma-requests/{requestId}/…`) dzielą jeden człon w liczbie mnogiej.

| Metoda | Endpoint | Zwraca |
|---|---|---|
| `list(int $page = 1)` | `GET /v1/rma-requests?page=N` | `ResourceCollection<RmaRequest>` |
| `iterate()` | `GET /v1/rma-requests`, potem `view.next` | `Generator<int, RmaRequest>` |
| `get(int $id)` | `GET /v1/rma-requests/{id}` | `RmaRequest` |
| `create(CreateRmaRequest $request)` | `POST /v1/rma-requests` | `RmaRequest` - jedyny zapis zwracający model |

### RmaRequests - akcje

Każda akcja bierze `$requestId` jako pierwszy argument i zwraca `void`: odpowiedź to echo
wysłanego payloadu, więc **świeży `get()` jest jedynym sposobem, żeby zobaczyć nowy stan**.
Sukces sygnalizuje brak wyjątku.

Kolumna „Wysyłane body” ma znaczenie przy porównywaniu z logami serwera - większość akcji
duplikuje `requestId` do body obok ścieżki, bo spec nie rozstrzyga, który z nich czyta serwer.

| Metoda | Endpoint | Wysyłane body |
|---|---|---|
| `changeStatus(int $requestId, string $stateIdentifier)` | `POST /v1/rma-requests/{requestId}/status` | `{requestId, stateIdentifier}` |
| `assignOwner(int $requestId, int $userId)` | `POST /v1/rma-requests/{requestId}/owner` | `{requestId, userId}` |
| `unassignOwner(int $requestId)` | `DELETE /v1/rma-requests/{requestId}/owner` | brak |
| `addFollower(int $requestId, ?int $userId = null)` | `POST /v1/rma-requests/{requestId}/follower` | `{userId}` albo `{}`, żeby obserwować jako uwierzytelniony użytkownik. **Bez `requestId`** - to jedyny schemat akcji, którego wszystkie człony opisują *użytkownika*, więc wysłanie id żądania wskazałoby niewłaściwego |
| `removeFollower(int $requestId)` | `DELETE /v1/rma-requests/{requestId}/follower` | brak. Brak parametru `$userId` - endpoint nie ma gdzie go przyjąć, a serwer ignorujący niezadeklarowane body skasowałby *własną* obserwację wywołującego i zwrócił `204` |
| `followers(int $requestId)` | `GET /v1/rma-requests/{requestId}/followers` | - zwraca `ResourceCollection<RmaRequestFollower>`; parametr `page` nie istnieje |
| `iterateFollowers(int $requestId)` | to samo, potem `view.next` | `Generator<int, RmaRequestFollower>` |
| `addMessage(int $requestId, string $message, bool $public = false)` | `POST /v1/rma-requests/{requestId}/message` | `{requestId, message, public}`. Domyślnie notatka wewnętrzna: wiadomość widoczna dla klienta jest nieodwracalna |
| `addStamp(int $requestId, string $stampId)` | `POST /v1/rma-requests/{requestId}/stamp` | `{requestId, stampId}` |
| `star(int $requestId)` | `POST /v1/rma-requests/{requestId}/star` | `{requestId, starred: true}` |
| `unstar(int $requestId)` | `DELETE /v1/rma-requests/{requestId}/star` | brak |
| `setDeadline(int $requestId, string\|DateTimeInterface $deadline)` | `POST /v1/rma-requests/{requestId}/deadline` | `{requestId, deadline}`; `DateTimeInterface` jest formatowany jako `Y-m-d`, string idzie dosłownie |
| `setApprovedAmount(int $requestId, float $amount, string $currency)` | `POST /v1/rma-requests/{requestId}/approved-amount` | `{requestId, amount, currency}` |
| `addAttachments(int $requestId, array $files)` | `POST /v1/rma-requests/{requestId}/attachments` | `{requestId, files}` - **JSON, nie multipart**. Endpoint konsumuje `application/json` z tablicą stringów `files`; czym te stringi są (URL-e, base64, id wcześniejszych uploadów), spec nie mówi nigdzie, więc idą nietknięte |
| `updateProduct(int $requestId, int $productId, UpdateProduct $update)` | `PUT /v1/rma-requests/{requestId}/product/{productId}` | `{requestId, productId, …UpdateProduct}` |
| `timeline(int $requestId)` | `GET /v1/rma-requests/{requestId}/timeline` | `ResourceCollection<TimelineEntry>`, od najnowszych |
| `iterateTimeline(int $requestId)` | to samo, potem `view.next` | `Generator<int, TimelineEntry>` |

`followers()` i `timeline()` zwracają **wyłącznie pierwszą stronę**. Ich `totalItems()` podaje
prawdziwą liczbę, więc wywołujący czytający samą kolekcję może po cichu przegapić wpisy - przy
czymkolwiek, co może przerosnąć jedną stronę, lepsze są warianty `iterate*`.

### RmaRequests - operacje bulk

Wszystkie pięć zwraca `void` i **nie raportuje wyniku per żądanie**: odpowiedź to echo listy
id, bez statusu dla każdego z osobna, więc nie dowiesz się z niej, które id się powiodły. Kiedy
to ma znaczenie, przelatuj pętlą po akcjach pojedynczych albo doczytaj żądania po fakcie.

Id niebędące liczbami całkowitymi są odrzucane lokalnie przez
`ConfigurationException::invalidRequestId()`, a nie rzutowane - ciche `(int) '12a'` trafiłoby
w żądanie 12, realny i niepowiązany rekord.

| Metoda | Endpoint | Wysyłane body |
|---|---|---|
| `bulkAssignOwner(array $requestIds, int $userId)` | `POST /v1/rma-requests/bulk/owner` | `{requestIds, userId}` |
| `bulkUnassignOwner(array $requestIds)` | `POST /v1/rma-requests/bulk/unassign-owner` | `{requestIds}` - `userId` nie jest wysyłane; spec nazywa je „ignorowanym przy unassign” |
| `bulkStar(array $requestIds)` | `POST /v1/rma-requests/bulk/star` | `{requestIds}` - bez członu `starred`, inaczej niż pojedyncze `star()` |
| `bulkUnstar(array $requestIds)` | `POST /v1/rma-requests/bulk/unstar` | `{requestIds}` |
| `bulkChangeStatus(array $requestIds, string $stateIdentifier)` | `POST /v1/rma-requests/bulk/status` | `{requestIds, statusId: $stateIdentifier}` - pole na drucie to **`statusId`**, podczas gdy pojedyncze `changeStatus()` wysyła `stateIdentifier`. Te dwa endpointy naprawdę są w specu niezgodne; ujednolicona jest tylko nazwa parametru PHP |

Dwie pułapki warte powtórzenia, bo odwracają to, co robią akcje pojedyncze:

- `unassign-owner` i `unstar` to **POST**, podczas gdy pojedyncze `unassignOwner()` i `unstar()` są DELETE-ami.
- `requestIds` serializuje się jako `int[]`, nie `string[]`, mimo że spec deklaruje stringi.

### Zasoby danych słownikowych

Identyczny kształt, po jednym modelu na zasób. Ścieżka itemu to ścieżka jego kolekcji plus id.

| Akcesor | Klasa | `list()` / `iterate()` | `get($id)` |
|---|---|---|---|
| `saleChannels()` | `SaleChannels` | `GET /v1/sale-channels` | `GET /v1/sale-channels/{id}` |
| `returnPoints()` | `ReturnPoints` | `GET /v1/return-points` | `GET /v1/return-points/{id}` |
| `orderedProducts()` | `OrderedProducts` | `GET /v1/ordered-products` | **brak** |
| `itemConditions()` | `ItemConditions` | `GET /v1/rma-request-items-conditions` | `GET /v1/rma-request-items-conditions/{id}` |
| `itemReasons()` | `ItemReasons` | `GET /v1/rma-request-items-reasons` | `GET /v1/rma-request-items-reasons/{id}` |
| `itemResolutions()` | `ItemResolutions` | `GET /v1/rma-request-items-resolutions` | `GET /v1/rma-request-items-resolutions/{id}` |

`OrderedProducts` nie ma `get()`, bo API nie publikuje operacji `/v1/ordered-products/{id}`.
Zasób bez endpointu itemu rzuca `ConfigurationException::noItemEndpoint()`, zamiast wysyłać
żądanie, które mogłoby skończyć się wyłącznie na 404.

## Kolekcje

### ResourceCollection

`RetJetApi\Returns\Collection\ResourceCollection<T of Model>` - `final readonly`, implementuje
`Countable` i `IteratorAggregate`. Jedna strona: zahydratowane człony plus metadane Hydry,
które z nimi przyszły.

| Metoda | Zwraca | Uwagi |
|---|---|---|
| `fromPayload(array $payload, string $model)` | `self<TModel>` | `static`; hydratuje zdekodowany payload kolekcji |
| `member()` | `list<T>` | zahydratowane człony tej strony |
| `totalItems()` | `int` | rozmiar **całego** zbioru wyników |
| `count()` | `int` | rozmiar **tej strony** - inna liczba, gdy tylko zbiór przekracza jedną stronę |
| `view()` | `array<string, string>` | człony `view` Hydry: `first`, `last`, `previous`, `next` |
| `nextPage()` | `?string` | URL następnej strony albo `null` na ostatniej |
| `previousPage()` | `?string` | URL z `view.previous` albo `null` |
| `hasNextPage()` | `bool` | - |
| `first()` | `?Model` | pierwszy człon tej strony |
| `isEmpty()` | `bool` | - |
| `getIterator()` | `ArrayIterator<int, T>` | `foreach` po tej stronie |
| `toArray()` | `array` | każdy człon przez `Model::toArray()` |

Mylenie `count()` z `totalItems()` to klasyczny błąd paginacji - dlatego są to dwie nazwy,
a nie jedna:

```php
$page = $client->rmaRequests()->list(page: 2);

$page->totalItems();   // the whole result set
count($page);          // this page only
```

API nie przyjmuje parametru rozmiaru strony - `page` jest jedynym, jaki akceptuje. Strona
mieściła **30 rekordów** przy ostatnim pomiarze na żywym środowisku, ale nic w API tego nie
gwarantuje, więc czytaj `count($page)`.

### Paginator

`RetJetApi\Returns\Collection\Paginator<T of Model>` - `final`, implementuje `IteratorAggregate`.
Przechodzi wszystkie strony, podążając za linkiem następnej strony od serwera, zamiast inkrementować
licznik, co utrzymuje SDK w zgodzie z API, gdyby to zmieniło sposób stronicowania.

| Metoda | Zwraca | Uwagi |
|---|---|---|
| `getIterator()` | `Generator<int, T>` | każdy element każdej strony, po kolei |
| `pages()` | `Generator<int, ResourceCollection<T>>` | same strony, dla wywołujących potrzebujących `totalItems()` w trakcie iteracji |

Nic nie jest pobierane, dopóki iteracja się nie zacznie, a strona N+1 jest żądana dopiero po
skonsumowaniu strony N - więc to jest bezpieczne dla zbiorów większych niż pamięć. `view.next`
wskazujące z powrotem na właśnie pobraną stronę jest traktowane jako koniec, bo proxy
przepisujące linki mogłoby inaczej zapętlić iterację w nieskończoność.

`pages()` jest sposobem na zachowanie metadanych przy strumieniowaniu:

```php
foreach ($client->rmaRequests()->iterate() as $rma) {
    // one item at a time, one HTTP request per page
}
```

**Bezwzględne URL-e wychodzące poza skonfigurowany base URI są odrzucane, nie odwiedzane.**
Każde żądanie niesie klucz API, a `view.next` to dane kontrolowane przez serwer, więc
spreparowany link oddałby klucz obcemu hostowi; kontrola same-origin porównuje schemat, host
i port, a następnie podnosi `ConfigurationException::crossOriginRequest()`.

## Modele

### Interfejs Model

`RetJetApi\Returns\Model\Model` - implementowany przez wszystkie czternaście modeli. Są `final
readonly`, z publicznymi właściwościami zamiast akcesorów, a każda właściwość jest nullowalna,
bo spec prawie niczego nie oznacza jako wymagane.

| Człon | Sygnatura | Uwagi |
|---|---|---|
| `fromArray()` | `static fromArray(array $data): static` | hydratuje ze zdekodowanego payloadu |
| `toArray()` | `toArray(): array` | typowane właściwości, z przywróconymi nazwami kluczy API |
| `raw()` | `raw(): array` | **cały zdekodowany payload**, nie tylko nieznane klucze |

`raw()` jest tym, co czyni SDK zgodnym w przód: człon dodany przez API jutro jest osiągalny
dziś, a hydratacja nigdy nie wysypie się na polu, którego nie zna.

### RmaRequest

Centralny zasób. Znaczniki czasu są w sekundach uniksowych, tak jak przysyła je API.

| Właściwość | Typ | Uwagi |
|---|---|---|
| `$id` | `?int` | |
| `$uuid` | `?string` | |
| `$identifier` | `?string` | np. `RMA-2024-001234` |
| `$type` | `?string` | np. `return`, `warranty` |
| `$createdAt` | `?int` | sekundy uniksowe |
| `$deadlineTs` | `?int` | sekundy uniksowe |
| `$customer` | `?RmaRequestCustomer` | jeden z tylko dwóch realnie zagnieżdżonych obiektów |
| `$customerLocale` | `?string` | |
| `$customerInfo` | `?string` | |
| `$refundBankAccountNo` | `?string` | |
| `$totalRequestedAmount` | `?float` | |
| `$totalRequestedCurrency` | `?string` | |
| `$totalConfirmedAmount` | `?float` | |
| `$totalConfirmedCurrency` | `?string` | |
| `$state` | `?RmaRequestState` | drugi zagnieżdżony obiekt |
| `$saleChannel` | `?SaleChannel` | zagnieżdżony obiekt; wcześniejsze wersje API wysyłały tu IRI |
| `$items` | `?list<RmaRequestItem>` | pozycje produktowe zgłoszenia |
| `$confirmations` | `?list<RmaRequestFile>` | dokumenty wygenerowane przez proces zwrotu, np. PDF potwierdzenia |
| `$attachments` | `?list<RmaRequestFile>` | dowody przesłane przez klienta lub agenta |

`createdAtAsDateTime()` i `deadlineTsAsDateTime()` zwracają odpowiadające `?int` jako UTC
`DateTimeImmutable`, albo `null`, gdy właściwość źródłowa jest `null`.

`items`, `confirmations` i `attachments` zostają `null`, gdy API całkowicie pomija dany człon -
to co innego niż „nie ma żadnych", co API wyraża pustą listą. Te trzy były wcześniej
deklarowane w specu jako `string[]`, mimo że w praktyce były obiektami, więc SDK trzymało je
nietypowane w `raw()`; spec teraz nazywa ich prawdziwe schematy (`RmaRequestItem`,
`RmaRequestFile`) i właściwości są odpowiednio typowane.

### RmaRequestCustomer

| Właściwość | Typ |
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

Jedna pozycja produktowa w ramach zgłoszenia RMA: co zażądano, co potwierdzono i do jakiego
zamówionego produktu się odnosi.

| Właściwość | Typ | Uwagi |
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
| `$rmaRequestItemReason` | `?string` | **IRI**, nie zagnieżdżony obiekt |
| `$rmaRequestIItemResolution` | `?string` | **IRI**; podwójne „I” to klucz wprost ze specu, zachowany dosłownie, a nie „poprawiony” |
| `$rmaRequestIItemCondition` | `?string` | **IRI**; ta sama pisownia z podwójnym „I” |
| `$orderedProduct` | `?OrderedProduct` | zagnieżdżony oryginalny produkt z zamówienia, albo `null` |

### RmaRequestFile

Plik dołączony do zgłoszenia RMA - dokument wygenerowany przez proces zwrotu
(`RmaRequest::$confirmations`) albo dowód przesłany przez klienta lub agenta
(`RmaRequest::$attachments`).

| Właściwość | Typ |
|---|---|
| `$url` | `?string` |
| `$mime` | `?string` |

### RmaRequestState

| Właściwość | Typ | Uwagi |
|---|---|---|
| `$label` | `?string` | identyfikator maszynowy - po nim porównywać |
| `$labelTranslated` | `?string` | forma wyświetlana w lokalizacji konta; nigdy jako klucz |
| `$state` | `?string` | stan workflow, do którego etykieta należy |
| `$labelColor` | `?string` | |

### RmaRequestFollower

Wszystkie człony opisują **użytkownika**, nigdy żądania RMA - to jedyny schemat akcji bez
członu na samo żądanie, które niesie wyłącznie ścieżka.

| Właściwość | Typ | Uwagi |
|---|---|---|
| `$id` | `?int` | w specu opisane jako „User ID of the follower” |
| `$email` | `?string` | |
| `$name` | `?string` | |
| `$userId` | `?int` | „User ID to follow/unfollow” |

`$id` i `$userId` wyglądają redundantnie, a to, które z nich wypełnia realna odpowiedź, jest
niepotwierdzone.

### TimelineEntry

W specu `RmaRequestTimeline`.

| Właściwość | Typ | Uwagi |
|---|---|---|
| `$id` | `?int` | |
| `$type` | `?string` | |
| `$createdAt` | `?int` | sekundy uniksowe |
| `$description` | `?string` | |
| `$public` | `?bool` | widoczne dla klienta albo tylko wewnętrznie |

`createdAtAsDateTime()` zwraca `$createdAt` jako UTC `DateTimeImmutable`, albo `null`, gdy jest
`null`.

`user` i `data` zostają w `raw()`: spec deklaruje oba jako `string[]`, co nie odpowiada temu,
czym referencja do aktora i payload zdarzenia mogą sensownie być.

### SaleChannel

| Właściwość | Typ | Uwagi |
|---|---|---|
| `$id` | `?int` | |
| `$label` | `?string` | |
| `$name` | `?string` | |
| `$channelType` | `?string` | |
| `$maxReturnDaysProcessingPolicy` | `?int` | |
| `$maxWarrantyDaysProcessingPolicy` | `?int` | |
| `$returnPointAddress` | `?ReturnPoint` | zagnieżdżony obiekt; obecny, gdy kanał czytany jest osobno - na liście zwykle jest sam `$returnPointId` |
| `$returnPointId` | `?int` | zawsze obecny |

### ReturnPoint

Fizyczny adres, na który odsyłany jest zwracany towar. Wszystkie właściwości `?string` poza
`$id`.

| Właściwość | Typ |
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

Pozycja produktowa z oryginalnego zamówienia. W tym API tylko do odczytu: jest endpoint
kolekcji, nie ma endpointu itemu.

| Właściwość | Typ |
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

`$orderId`, `$productId` i `$lineId` to identyfikatory własne kanału sprzedaży - przekaż je
z powrotem jako `CreateRmaRequestItem::$orderId`/`$productId`/`$lineId` przy tworzeniu zwrotu.

### ItemCondition, ItemReason, ItemResolution

Trzy niezależne klasy słownikowe bez wspólnej bazy - opisują różne rzeczy i w większości mają
ten sam kształt.

| Właściwość | Typ | Uwagi |
|---|---|---|
| `$id` | `?int` | |
| `$label` | `?string` | stabilny identyfikator maszynowy, po którym porównywać |
| `$labelTranslated` | `?string` | forma wyświetlana w lokalizacji konta; nie wolno używać jako klucza |
| `$isActive` | `?bool` | czy opcja jest wciąż oferowana przez sklep |
| `$position` | `?int` | kolejność wyświetlania w konfiguracji sklepu |

`ItemReason` i `ItemResolution` dodatkowo typują `$isReturn` i `$isWarranty` (`?bool`, czy
opcja dotyczy zwrotów / reklamacji gwarancyjnych) oraz `$returnPosition` (`?int`, kolejność
wyświetlania w procesie zwrotu). Samo `ItemResolution` typuje też `$warrantyPosition` (`?int`,
kolejność wyświetlania w procesie reklamacji gwarancyjnej).

## Payloady żądań

Obiekty payloadu wysyłają **wyłącznie człony, które ustawisz**. Nieustawiony człon jest
pomijany, a nie wysyłany jako jawny `null`, bo te dwie rzeczy znaczą dla serwera co innego.

### Payload

`RetJetApi\Returns\Request\Payload` - jedna metoda, `toArray(): array`. Zaimplementuj ją, żeby
przekazać własny obiekt body do metody zasobu.

### CreateRmaRequest

Body dla `POST /v1/rma-requests`. API opisuje to teraz osobnym schematem
(`RmaRequest.CreateRmaRequest`) zamiast korzystać z kształtu odczytu `RmaRequest`, i jest on
znacznie węższy: `$saleChannelId` to zwykłe id, nie IRI zwracane przy odczycie, a nie ma
członu na `identifier`, `uuid`, `totalRequestedAmount`, `totalRequestedCurrency` ani
`customerLocale` - te są nadawane albo wyliczane przez serwer i nie da się ich ustawić przy
tworzeniu.

`$saleChannelId` i `$customer` są wymagane przez schemat. `$items` formalnie nie jest w liście
`required`, ale opis samego schematu mówi „at least one is required" (co najmniej jeden jest
wymagany), więc SDK też tego wymaga, zamiast ufać pominięciu, które i tak zakończyłoby się
tylko `400` z serwera.

| Parametr | Typ | Uwagi |
|---|---|---|
| `$saleChannelId` | `int` | **wymagane** |
| `$customer` | `CreateRmaRequestCustomer` | **wymagane** |
| `$items` | `list<CreateRmaRequestItem>` | **wymagane**, co najmniej jeden |
| `$type` | `?string` | np. `return`, `warranty`; po pominięciu domyślnie `return` po stronie serwera |
| `$customerInfo` | `?string` | |
| `$customerInstruction` | `?string` | |
| `$refundBankAccountNo` | `?string` | |
| `$extra` | `array` | wypełnia luki po nazwanych parametrach powyżej, które zostały na `null`; nazwany parametr, który jest ustawiony, wygrywa z tym samym kluczem tutaj |

### CreateRmaRequestCustomer

Człon `$customer` klasy `CreateRmaRequest`. `$email`, `$firstName`, `$lastName`, `$country`,
`$city` i `$address1` są wymagane przez schemat; reszta jest opcjonalna. Inaczej niż w
odczytowym `RmaRequestCustomer`, klucze na drucie są tu już camelCase - nie ma tu snake_case
do tłumaczenia.

| Parametr | Typ | Uwagi |
|---|---|---|
| `$email` | `string` | **wymagane** |
| `$firstName` | `string` | **wymagane** |
| `$lastName` | `string` | **wymagane** |
| `$country` | `string` | **wymagane** |
| `$city` | `string` | **wymagane** |
| `$address1` | `string` | **wymagane** |
| `$address2` | `?string` | |
| `$zip` | `?string` | |
| `$state` | `?string` | |
| `$phone` | `?string` | |
| `$company` | `?string` | |
| `$taxId` | `?string` | |
| `$extra` | `array` | |

### CreateRmaRequestItem

Jeden wpis członu `$items` klasy `CreateRmaRequest`. `$orderId` i `$productId` identyfikują
zamówiony produkt tak, jak robi to kanał sprzedaży - te same wartości, jakie przy odczycie
zwracają `OrderedProduct::$orderId`/`$productId`; `$lineId` dodatkowo rozróżnia je tam, gdzie
kanał sprzedaży odróżnia wiele linii tego samego produktu. `$orderId`, `$productId`,
`$quantity`, `$reasonId` i `$conditionId` są wymagane przez schemat; `$resolutionId` i reszta
są opcjonalne.

| Parametr | Typ | Uwagi |
|---|---|---|
| `$orderId` | `string` | **wymagane** |
| `$productId` | `string` | **wymagane** |
| `$quantity` | `int` | **wymagane** |
| `$reasonId` | `int` | **wymagane**, z `GET /v1/rma-request-items-reasons` |
| `$conditionId` | `int` | **wymagane**, z `GET /v1/rma-request-items-conditions` |
| `$lineId` | `?string` | |
| `$name` | `?string` | |
| `$price` | `?float` | |
| `$currency` | `?string` | |
| `$resolutionId` | `?int` | z `GET /v1/rma-request-items-resolutions` |
| `$extra` | `array` | |

### UpdateProduct

Body dla `PUT /v1/rma-requests/{requestId}/product/{productId}` - potwierdzona ilość i kwota,
na jakich agent rozlicza pojedynczy zwracany produkt.

| Parametr | Typ |
|---|---|
| `$confirmedQty` | `?int` |
| `$confirmedAmount` | `?float` |
| `$confirmedCurrency` | `?string` |
| `$extra` | `array` |

`requestId` i `productId` nie są częścią tego obiektu: identyfikują cel i są argumentami
`updateProduct()`, która umieszcza je w ścieżce.

## Wyjątki

### Hierarchia

Wszystko, co SDK rzuca, implementuje `RetJetApi\Returns\Exception\RetJetException` - interfejs
znacznikowy rozszerzający `Throwable` - więc jeden `catch` pokrywa całą bibliotekę.

| Klasa | Rozszerza | Rzucana, gdy |
|---|---|---|
| `ApiException` | `RuntimeException` | dowolne `4xx`, którego SDK nie mapuje na coś węższego, w tym `400` |
| `AuthenticationException` | `ApiException` | `401` |
| `AccessDeniedException` | `ApiException` | `403` |
| `NotFoundException` | `ApiException` | `404` |
| `ValidationException` | `ApiException` | `422` |
| `RateLimitException` | `ApiException` | `429` |
| `ServerException` | `ApiException` | dowolne `5xx` |
| `TransportException` | `RuntimeException` | awaria sieci, brak odpowiedzi - opakowuje `ClientExceptionInterface` z PSR-18 |
| `MalformedResponseException` | `RuntimeException` | status sukcesu, nieużywalne ciało |
| `ConfigurationException` | `InvalidArgumentException` | lokalna błędna konfiguracja, zanim cokolwiek wyjdzie w sieć |

`400` jest wymienione osobno od `422`, bo jest udokumentowaną odpowiedzią tego API, a nie
przypadkiem teoretycznym - łapanie samego `ValidationException` przegapi realne błędy wejścia.

### ApiException i jego podklasy

| Metoda | Zwraca | Dostępna na |
|---|---|---|
| `status()` | `int` | każdym `ApiException`; status HTTP, rozstrzygający |
| `problem()` | `Problem` | każdym `ApiException` |
| `method()` | `string` | każdym `ApiException` - metoda HTTP żądania, które padło, np. `"GET"` |
| `path()` | `string` | każdym `ApiException` - ścieżka żądania, które padło, bez query stringa i bez hosta |
| `violations()` | `array<string, list<string>>` | `ValidationException` - ścieżka właściwości → komunikaty |
| `violationsFor(string $propertyPath)` | `list<string>` | `ValidationException` |
| `retryAfter()` | `?int` | `RateLimitException` - sekundy, czytane zarówno z formy liczbowej, jak i HTTP-date dopuszczanej przez nagłówek |
| `status()` | `int` | również `MalformedResponseException`, choć nie jest on `ApiException` |

`method()` i `path()` trafiają też do `getMessage()` - `HTTP 404: Not Found (DELETE
/v1/rma-requests/1234/follower)` - więc narzędzie do śledzenia błędów pokazujące samą wiadomość
nadal wskazuje, które wywołanie padło. Query string jest celowo wykluczony z obu: może nieść
parametry z linku `view.next` Hydry dostarczone przez wywołującego, a id żądania w ścieżce to
inny rodzaj danych.

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

**`TransportException` redaguje poświadczenia u źródła.** Osadza pełne URI żądania, więc base
URI w postaci `https://user:pass@host` zapisałby hasło wszędzie tam, gdzie ten wyjątek zostanie
złapany. Redakcja dzieje się w samym wyjątku, co chroni także własną obsługę błędów, nie tylko
logger SDK.

### Problem

`RetJetApi\Returns\Exception\Problem` - `final readonly`, dokument problemu RFC 7807. Niesiony
przez każdy `ApiException`; ignoruje człon `trace[]`, który dokleja środowisko dev.

| Metoda | Zwraca |
|---|---|
| `fromArray(array $payload, ?int $fallbackStatus = null)` | `static` |
| `type()` | `?string` |
| `title()` | `?string` |
| `status()` | `?int` |
| `detail()` | `?string` |
| `instance()` | `?string` |
| `summary()` | `?string` - konkretny `detail`, z odwrotem do ogólnego `title` |
| `toArray()` | `array` |

Każdy człon jest nullowalny, a na `401` **wszystkie** są nullem: inaczej niż każdy inny status
błędu w tym API, awaria uwierzytelnienia odpowiada `text/html` z gołym napisem
`Authentication failed`, a nie dokumentem problemu. `500`, którego `detail()` brzmi
`Unable to exchange token`, znaczy to samo - SDK nie przepisuje go na `AuthenticationException`,
bo oznaczałoby to dopasowywanie po treści komunikatu.

### ConfigurationException

Nazwane fabryki statyczne, każda dla konkretnej pomyłki wyłapanej przed wyjściem w sieć:

| Fabryka | Podnoszona, gdy |
|---|---|
| `missingApiKey()` | `build()` bez `withApiKey()` |
| `invalidBaseUri()` | base URI nie jest bezwzględnym URI http(s) |
| `invalidTimeout()` | timeout poniżej 1 sekundy |
| `negativeMaxRetries()` | ujemna liczba ponowień |
| `unencodableBody()` | body żądania, którego `json_encode` nie potrafi zserializować |
| `timeoutOnSuppliedClient()` | `withTimeout()` w połączeniu z `withHttpClient()` |
| `timeoutNotSupported()` | `withTimeout()` na wykrytym kliencie, którego SDK nie umie skonfigurować |
| `invalidRequestId()` | id w operacji bulk niebędące ani intem, ani stringiem cyfrowym |
| `noItemEndpoint()` | `get()` na zasobie bez endpointu itemu |
| `crossOriginRequest()` | URL wychodzący poza skonfigurowany base URI - zabezpieczenie przed wyciekiem klucza API |
| `missingDiscovery()` | `php-http/discovery` nie znalazł implementacji PSR-17 ani PSR-18 |

## Transport i middleware

### Transport

`RetJetApi\Returns\Http\Transport` - jedna metoda, celowo, żeby dekorator forwardował pojedyncze
wywołanie i mógł je odtworzyć bez trzymania stanu:

```php
public function request(
    string $method,
    string $path,
    array $query = [],
    ?array $body = null,
    array $headers = [],
): array;
```

`$path` jest względne albo jest bezwzględnym URL-em dla linku `view.next`. Wartość zwracana to
zdekodowane ciało; błędy przychodzą wyjątkiem. `PsrTransport` to implementacja PSR-18 na dnie
stosu.

`MiddlewareInterface` rozszerza `Transport` i nie dodaje nic - middleware **jest** transportem
i to cały kontrakt. Stos jest składany wewnątrz `ClientBuilder::decorate()` w tej kolejności:

```
PsrTransport → LoggingMiddleware → RetryMiddleware → your middleware
```

Logowanie siedzi wewnątrz ponawiania, więc każda próba dostaje własny rekord; twoje middleware
jest najbardziej na zewnątrz, więc cache może odpowiedzieć bez budzenia logiki retry.

### RetryMiddleware

`RetJetApi\Returns\Http\Middleware\RetryMiddleware` - domyślnie w stosie z trzema ponowieniami;
`withRetry(0)` go usuwa.

| Parametr konstruktora | Domyślnie |
|---|---|
| `Transport $next` | - |
| `int $maxRetries` | `Configuration::DEFAULT_MAX_RETRIES` (3) |
| `?callable $sleeper` | `usleep()`; punkt wstrzyknięcia istnieje dla testów i dla kogoś, kto chce jitteru |
| `float $baseDelay` | `DEFAULT_BASE_DELAY` = `1.0` |
| `float $maxDelay` | `DEFAULT_MAX_DELAY` = `60.0` - sufit pojedynczego czekania |
| `float $maxTotalDelay` | `DEFAULT_MAX_TOTAL_DELAY` = `60.0` - sufit sumy czekań w jednym wywołaniu |

Co jest ponawiane, zależy od metody HTTP:

| Awaria | `GET`, `HEAD`, `PUT`, `DELETE`, `OPTIONS`, `TRACE` | `POST`, `PATCH` |
|---|---|---|
| `429` limit żądań | ponawiane | **ponawiane** |
| `5xx` błąd serwera | ponawiane | nie ponawiane |
| awaria sieci | ponawiane | nie ponawiane |
| cokolwiek innego | nie ponawiane | nie ponawiane |

`429` jest odpowiadane, zanim żądanie dotrze do handlera, więc powtórzenie jest bezpieczne
niezależnie od metody. `5xx` jest odwrotnością - serwer przyjął żądanie i dopiero potem gdzieś
padł - a PSR-18 spłaszcza „connection refused” i „read timeout po tym, jak serwer już
zadziałał” do tego samego wyjątku, więc nie da się ich rozróżnić. **Retry praktycznie nie
dotyka więc zapisów w tym API, bo prawie każdy zapis to POST. To jest zamierzone.**

Czasowanie to backoff wykładniczy od 1 s, podwajany co próbę. `429` niosące `Retry-After`
używa liczby od serwera. Oba sufity różnią się traktowaniem zbyt długiego czekania i różnica
jest celowa: nasz własny domysł backoffu jest **przycinany** do `maxDelay`, podczas gdy
instrukcja serwera `Retry-After` jest **wykonywana albo odrzucana**, nigdy skracana. Po
przekroczeniu któregokolwiek sufitu wyjątek leci dalej z nietkniętym `retryAfter()`, więc można
zaplanować pracę zamiast blokować workera.

### LoggingMiddleware

`RetJetApi\Returns\Http\Middleware\LoggingMiddleware` - nieaktywny do czasu `withLogger()`.

| Parametr konstruktora | Domyślnie |
|---|---|
| `Transport $next` | - |
| `LoggerInterface $logger` | - |
| `string $level` | `LogLevel::DEBUG` |
| `string $errorLevel` | `LogLevel::WARNING` |

Każda próba jest zapisywana, więc burza ponowień widać jako kilka linii, a nie jedną.
Zapisywane są: metoda, ścieżka, query, zamaskowane nagłówki, czas trwania i wynik.

Dwie rzeczy nie trafiają tam nigdy, a jednej z nich nie da się włączyć:

- **Poświadczenia.** Sześć nazw nagłówków jest maskowanych - `Authorization`,
  `Proxy-Authorization`, `Cookie`, `Set-Cookie`, `X-Api-Key`, `X-Auth-Token` - a poświadczenia
  osadzone w base URI są usuwane z komunikatów wyjątków.
- **Ciała żądań i odpowiedzi, bez flagi, która by to włączyła.** `RmaRequest` niesie e-mail
  klienta, adres pocztowy i `refundBankAccountNo`, a payload `CreateRmaRequest` niesie to samo.
  Wpisanie tego do loga debugowego przenosi dane osobowe i bankowe do systemu o innej retencji
  i innych prawach dostępu niż API, zwykle bez czyjejkolwiek decyzji.

Awaria jest logowana jako klasa, komunikat i status, a nie jako `Throwable` pod kluczem
`exception`. To celowe odejście od konwencji PSR-3; sam wyjątek i tak leci dalej nietknięty.

## Poza publicznym API

To istnieje w `src/` i nie jest objęte obietnicą zgodności:

| Klasa | Czym jest |
|---|---|
| `Model\Hydration` | `@internal` czytnik typów używany przez każde `fromArray()` |
| `Http\Redact` | redakcja poświadczeń używana przez `TransportException` i logger |
| `Http\RequestBuilder` | składanie URI, query i nagłówków, wraz z kontrolą same-origin |
| `Http\ResponseParser` | dekodowanie JSON, rozpakowanie Hydry, mapowanie statusów na wyjątki |
| `Http\HttpClientFactory` | discovery i konfiguracja timeoutu dla klienta budowanego przez SDK |

`protected` API `AbstractResource` - `fetch()`, `post()`, `put()`, `delete()`, `item()`,
`collection()`, `paginate()`, `hydrate()` - **jest** punktem rozszerzeń i jest opisane
w [Rozszerzaniu klienta](extending.md).

Jedna znana chropowatość: `Hydration` jest oznaczone `@internal`, podczas gdy `Model` jest
publicznym kontraktem, więc napisanie własnego modelu oznacza dziś przepisanie jego logiki.
Jest to odnotowane jako zadanie do zrobienia w przyszłości.
