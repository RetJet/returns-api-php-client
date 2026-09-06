# Wpięcie w Symfony

[English](../en/symfony.md) · **Polski**

Pakiet nie zawiera bundla. Wpięcie to kilka linii w `services.yaml` i jest to decyzja
świadoma: bundle oznaczałby kolejne wydanie do utrzymania i macierz wersji do pilnowania, nie
oszczędzając nikomu istotnej pracy.

## Minimalna konfiguracja

Klucz trafia do `.env` (a prawdziwa wartość do `.env.local`, którego się nie commituje):

```dotenv
RETJET_API_KEY=your-api-key
```

Rejestracja klienta jako usługi w `config/services.yaml`:

```yaml
RetJetApi\Returns\Client:
    factory: ['RetJetApi\Returns\Client', 'create']
    arguments: ['%env(RETJET_API_KEY)%']
```

To wszystko. `php-http/discovery` samo wykryje `symfony/http-client`, jeśli projekt go ma;
jeśli nie ma, trzeba go zainstalować:

```bash
composer require symfony/http-client nyholm/psr7
```

Od tej chwili `Client` można autowirować wszędzie:

```php
namespace App\Controller;

use RetJetApi\Returns\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

final class ReturnsController extends AbstractController
{
    public function __construct(private readonly Client $client)
    {
    }

    public function index(): Response
    {
        $page = $this->client->rmaRequests()->list(page: 1);

        return $this->render('returns/index.html.twig', [
            'requests' => $page->member(),
            'total' => $page->totalItems(),
        ]);
    }
}
```

## Logowanie, ponawianie i pozostałe ustawienia

Wszystko poza kluczem API przechodzi przez builder, co dalej jest jedną definicją usługi:

```yaml
RetJetApi\Returns\Client:
    factory: ['App\Factory\RetJetClientFactory', 'create']
    arguments:
        $apiKey: '%env(RETJET_API_KEY)%'
        $logger: '@monolog.logger.retjet'
```

```php
namespace App\Factory;

use Psr\Log\LoggerInterface;
use RetJetApi\Returns\Client;

final class RetJetClientFactory
{
    public static function create(string $apiKey, LoggerInterface $logger): Client
    {
        return Client::builder()
            ->withApiKey($apiKey)
            ->withLogger($logger)
            ->withTimeout(30)
            ->build();
    }
}
```

Osobny kanał Monologa deklaruje się w `config/packages/monolog.yaml`:

```yaml
monolog:
    channels: ['retjet']
```

SDK zapisuje jeden rekord na próbę HTTP na poziomie `debug` i jeden na awarię na poziomie
`warning`. Ciała nie są logowane nigdy, więc do kanału nie trafiają dane osobowe - patrz
[sekcja o logowaniu w README](README.md#logowanie).

## Wstrzyknięcie konkretnego klienta HTTP

Kiedy w projekcie jest kilka implementacji PSR-18 albo klient HTTP potrzebuje opcji, których
discovery nie zgadnie, podaj go wprost. Pamiętaj, że `withHttpClient()` i `withTimeout()`
wykluczają się - timeout ustaw na samym kliencie:

```php
namespace App\Factory;

use RetJetApi\Returns\Client;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

final class RetJetClientFactory
{
    public static function create(string $apiKey): Client
    {
        $httpClient = new Psr18Client(HttpClient::create([
            'timeout' => 30,
            'max_duration' => 60,
        ]));

        return Client::builder()
            ->withApiKey($apiKey)
            ->withHttpClient($httpClient)
            ->build();
    }
}
```

## Wstrzykiwanie samych zasobów

Jeśli usługa potrzebuje tylko jednego zasobu, wstrzyknij ten zasób zamiast całego klienta:

```yaml
RetJetApi\Returns\Resource\RmaRequests:
    factory: ['@RetJetApi\Returns\Client', 'rmaRequests']
```

## Komendy konsolowe i długo żyjące workery

`iterate()` pobiera jedną stronę na żądanie HTTP i nigdy nie trzyma całego zbioru wyników w
pamięci - dokładnie tego chcesz w komendzie przechodzącej po wszystkich żądaniach:

```php
foreach ($client->rmaRequests()->iterate() as $rma) {
    $output->writeln($rma->identifier ?? '(no identifier)');
}
```

W workerze Messengera pamiętaj, że ponawianie blokuje proces: SDK czeka w obrębie jednego
wywołania łącznie do 60 s, zanim się podda. Jeśli to za długo dla Twojego workera, obniż limit
przez `withRetry()` albo obsłuż `RateLimitException` samodzielnie i wróć wiadomość do kolejki,
korzystając z `retryAfter()`.

## Rozwiązywanie problemów

| Objaw | Przyczyna |
|---|---|
| `ConfigurationException: No PSR-18 HTTP client could be discovered` | brak zainstalowanej implementacji PSR-18 - dodaj `symfony/http-client` i `nyholm/psr7` |
| `ConfigurationException: A timeout cannot be applied to an HTTP client the SDK did not build` | `withHttpClient()` użyte razem z `withTimeout()` |
| `AuthenticationException` (`401`) | klucz API jest zły lub odwołany - normalny sposób, w jaki to zawodzi |
| `ServerException` z `detail: "Unable to exchange token"` | sama wymiana klucza nie działa, co też może wywołać zły lub odwołany klucz, mimo statusu `5xx` |
| `404` z ciałem HTML | bazowy URI wskazuje host, który nie serwuje API |
