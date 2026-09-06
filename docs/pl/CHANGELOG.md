# Changelog

[English](../../CHANGELOG.md) · **Polski**

Wszystkie istotne zmiany w tym projekcie są dokumentowane w tym pliku.

Format opiera się na [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
a projekt stosuje [wersjonowanie semantyczne](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0-beta.1] - 2026-09-06

Pierwsze otagowane wydanie. Pakiet pokrywa całość dokumentu OpenAPI w postaci, w jakiej serwuje
go produkcja; pytanie otwarte poniżej to istniejąca wcześniej luka w samym API, a nie bloker dla
tego taga.

### Dodane

- `Client::create()` i `Client::builder()` jako jedyny punkt wejścia; klucz API to jedyne
  wymagane ustawienie.
- Wszystkie 34 operacje z dokumentu OpenAPI, każda pod członem ścieżki w liczbie mnogiej, tak
  jak serwuje je produkcja (`/v1/rma-requests/{id}`, `/v1/rma-requests/{requestId}/status`,
  `/v1/sale-channels/{id}` i tak dalej). Wcześniejsza wersja tego klienta powstała wobec opisu,
  w którym elementy i akcje leżały pod członem w liczbie pojedynczej; ten opis nie odpowiada już
  żadnemu środowisku.
- `CreateRmaRequest`, wraz z `CreateRmaRequestCustomer` i `CreateRmaRequestItem` dla wymaganych
  pól `customer` i `items`, zbudowany wokół dedykowanego schematu tworzenia, a nie pełnego
  kształtu `RmaRequest`. `type` wybiera `return` albo `warranty`, więc reklamację gwarancyjną
  zakłada się tak samo jak zwrot.
- Zasoby odczytu: `saleChannels()`, `returnPoints()`, `orderedProducts()`, `itemConditions()`,
  `itemReasons()`, `itemResolutions()`.
- `rmaRequests()` z kolekcją, elementem, `create()`, piętnastoma akcjami jednostkowymi i
  pięcioma operacjami bulk - wszystkie jako płaskie metody z `$requestId` na pierwszym miejscu.
  `bulkChangeStatus()` nazywa swój drugi parametr `$stateIdentifier`, tak samo jak
  `changeStatus()`, więc argument nazwany brzmi tak samo w obu, mimo że payload na drucie
  zachowuje własny klucz API `statusId`.
- Modele `readonly` z `fromArray()`, `toArray()` i `raw()`; nieznane pola odpowiedzi pozostają
  dostępne przez `raw()`, więc pole dodane przez API nie psuje hydratacji.
- `ResourceCollection` dla jednej strony i `Paginator` do leniwej iteracji po wszystkich
  stronach, podążający za linkiem następnej strony od serwera. To API raportuje paginację
  w nagłówkach, a nie w ciele odpowiedzi - `X-Total-Count` oraz `Link: rel="next"` - więc parser
  składa je do tego samego kształtu, który niosłaby koperta Hydry; `totalItems()` jest zatem
  rozmiarem całego zbioru, nie strony.
- Hierarchia wyjątków za interfejsem znacznikowym `RetJetException`, mapująca statusy HTTP na
  `AuthenticationException`, `AccessDeniedException`, `NotFoundException`,
  `ValidationException`, `RateLimitException`, `ServerException`, `ApiException` oraz
  `TransportException`, `MalformedResponseException` i `ConfigurationException`.
- Dokumenty problemu RFC 7807 na każdym wyjątku API, z pominięciem pola `trace[]`, które
  dokłada środowisko deweloperskie.
- `method()` i `path()` na każdym `ApiException`, dołączone też do `getMessage()` - bez query
  stringa i bez hosta - więc narzędzie do śledzenia błędów potrafi wskazać, które wywołanie
  padło.
- `RetryMiddleware`, włączony domyślnie z trzema ponowieniami; `withRetry(0)` go usuwa.
- `LoggingMiddleware`, włączany przez `withLogger()`.
- `withMiddleware()` do własnych dekoratorów transportu.
- `array $query` na `list()` i `iterate()` każdego zasobu, przekazywane wprost jako dodatkowe
  parametry query-string łączone z `page`; `AbstractResource::collection()` już to przyjmowała,
  ale żadna publiczna metoda nie dawała sposobu, by to przekazać.
- `Configuration::defaultUserAgent()` odczytuje zainstalowaną wersję pakietu z
  `Composer\InstalledVersions` w momencie wywołania zamiast wersji zamrożonej w kodzie, więc
  nagłówek `User-Agent` nazywa faktycznie działające wydanie.
- `RmaRequest::createdAtAsDateTime()`/`deadlineTsAsDateTime()` i
  `TimelineEntry::createdAtAsDateTime()`, konwertujące uniksowe właściwości `?int` na UTC
  `DateTimeImmutable` dla tych, którzy nie chcą robić tej konwersji samodzielnie.
- Sekcja „Testowanie własnej integracji” w README: `Client` jest `final readonly` celowo, a
  szew PSR-18 (drugi argument `Client::create()`, `ClientBuilder::withHttpClient()`) to sposób
  na podstawienie fałszywego backendu zamiast tego.
- Sekcja „Zgodność” w README opisująca, co obejmuje obietnica semver: `@internal` i
  `@experimental` są wyłączone, modele mogą zyskiwać właściwości, akcja zwracająca `void` może
  zacząć zwracać wartość, a klucze `$query` są tak stabilne, jak (nieudokumentowane) wsparcie
  API dla nich.

### Bezpieczeństwo

- Logger maskuje sześć nagłówków niosących poświadczenia i usuwa poświadczenia osadzone w
  bazowym URI z komunikatów wyjątków.
- Ciała żądań i odpowiedzi nie są logowane nigdy i nie da się tego włączyć: `RmaRequest` niesie
  e-mail, adres pocztowy i numer konta bankowego klienta.
- Absolutne URL-e wychodzące poza skonfigurowany bazowy URI są odrzucane, a nie odwiedzane -
  każde żądanie niesie klucz API, a link Hydra `view.next` to dane kontrolowane przez serwer.

### Znane ograniczenia

- **Odrzucony klucz zwraca `401` bez dokumentu problemu.** Odpowiedź przychodzi jako
  `text/html` z gołym napisem `Authentication failed`, więc `AuthenticationException::problem()`
  nie ma `detail()`. Żądanie zupełnie bez nagłówka `Authorization` to drugi przypadek i dokument
  problemu niesie, więc `detail()` równe `null` mówi, że klucz został odrzucony, a nie że go
  brakowało. `500` z
  `detail: "Unable to exchange token"` znaczy to samo i nadal może wystąpić, gdy usługa wymiany
  tokenu nie działa; SDK nie przepisuje go na `AuthenticationException`.
- **Akcje zwracają `void`.** Ich odpowiedzi są echem wysłanego payloadu, więc do zobaczenia
  nowego stanu potrzebny jest osobny `get()`.
- **Operacje bulk nie raportują wyniku per żądanie.** Odpowiedź jest echem listy ID, bez statusu
  dla poszczególnych pozycji.
- **`removeFollower()` nie odsubskrybuje innego użytkownika.** Endpoint nie przyjmuje ani body,
  ani parametru query, więc metoda przyjmuje wyłącznie `$requestId` - nie ma gdzie nazwać
  innego użytkownika, a serwer ignorujący niezadeklarowane body po cichu skasowałby własną
  obserwację wywołującego.
- **`orderedProducts()` wyszukuje, a nie listuje.** Endpoint odrzuca żądanie bez kryteriów,
  więc `list()` i `iterate()` zwracają `400`, dopóki `$query` nie poda jednego z zestawów:
  `email` + `orderId`, `usernameAllegro` + `phone`, albo samego `parcelTrackingCode`. Spec
  deklaruje te parametry, nie oznaczając żadnego jako wymagany, bo OpenAPI nie potrafi wyrazić
  „jedna z tych trzech kombinacji".
- **Zapisy w praktyce nie są ponawiane**, bo prawie każdy zapis w tym API to POST, a
  powtórzenie go po niejednoznacznej awarii mogłoby go zdublować.
- **Domyślny `timeout` jest egzekwowany wyłącznie dla klienta, którego SDK buduje sam.** PSR-18
  nie daje sposobu na zastosowanie timeoutu do klienta skonstruowanego przez kogoś innego.
- `user` i `data` we wpisie osi czasu są dostępne wyłącznie przez `raw()`. Spec deklaruje oba
  jako tablice stringów, czemu realna odpowiedź przeczy, więc otypowanie ich ze schematu
  utrwaliłoby tę pomyłkę w publicznym API. `items[]`, `confirmations[]` i `attachments[]`
  w `RmaRequest` były w tej samej sytuacji, dopóki spec nie zaczął ich opisywać poprawnie;
  dziś są otypowane (`RmaRequestItem[]`, `RmaRequestFile[]`).

### Rozstrzygnięte przed tym tagiem

Trzy pytania, wokół których budowany był ten klient, doczekały się odpowiedzi: dwa dzięki temu,
że API zaczęło poprawnie opisywać samo siebie, jedno dzięki pomiarowi:

- **`429` faktycznie niesie `Retry-After`.** Zweryfikowane na produkcji, obok
  `X-RateLimit-Limit`, `-Remaining` i `-Reset`; spec dokumentuje dziś wszystkie cztery przy
  każdej operacji. `RetryMiddleware` honoruje `Retry-After`, więc czeka tyle, ile każe serwer,
  zamiast zgadywać.
- **Prefiks `Bearer ` nie jest rozbieżnością.** Schemat bezpieczeństwa nazywa się `ApiKey`,
  a jego opis wypisuje prefiks wprost - i to właśnie SDK wysyła.
- **Endpointy akcji czytają id żądania ze ścieżki.** Każdy schemat akcji oznacza `requestId`
  jako `readOnly`, więc kopia, którą SDK wysyła dodatkowo w body, jest nadmiarowa, a nie nośna.
  Nadal jest wysyłana, bo serwer czytający którekolwiek z tych dwóch miejsc dostanie tę samą
  wartość.

Trzy endpointy, których nie dało się sprawdzić, gdy powstawały tamte pytania, zostały od tego
czasu wywołane na produkcji prawdziwym kluczem. `GET /v1/sale-channels`, `GET .../followers`
i `GET .../timeline` zwracają `200`; `GET /v1/ordered-products` zwraca `400`, dopóki nie dostanie
kryteriów wyszukiwania, co jest opisanym wyżej ograniczeniem, a nie usterką.

### Niesprawdzone

Każda operacja odczytu została wywołana na produkcji. **Dziewiętnaście operacji zapisu nie**:
zakładanie zgłoszenia, piętnaście akcji jednostkowych i pięć operacji bulk zmieniają prawdziwe
zwroty, więc zostały nietknięte. Ich ścieżki i kształty payloadów zgadzają się z dokumentem
i nic ponad to nie jest o nich twierdzone.

[Unreleased]: https://github.com/RetJet/returns-api-php-client/compare/v0.1.0-beta.1...HEAD
[0.1.0-beta.1]: https://github.com/RetJet/returns-api-php-client/releases/tag/v0.1.0-beta.1
