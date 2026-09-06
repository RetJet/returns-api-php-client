# Wpięcie w Laravel

[English](../en/laravel.md) · **Polski**

Pakiet nie zawiera service providera, fasady ani pliku konfiguracyjnego do publikowania.
Wpięcie to kilka linii we własnym providerze aplikacji, dzięki czemu SDK nie musi pilnować
macierzy wersji Laravela.

Guzzle jest obecny w każdym szkielecie Laravela, więc `php-http/discovery` znajdzie klienta
HTTP bez żadnej dodatkowej instalacji.

## Minimalna konfiguracja

Klucz trafia do `.env`:

```dotenv
RETJET_API_KEY=your-api-key
```

Oraz do `config/services.php`, żeby przetrwał `php artisan config:cache`:

```php
return [
    // ...

    'retjet' => [
        'key' => env('RETJET_API_KEY'),
    ],
];
```

Rejestracja klienta w `app/Providers/AppServiceProvider.php`:

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use RetJetApi\Returns\Client;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Client::class, static fn (): Client => Client::create(
            (string) config('services.retjet.key'),
        ));
    }
}
```

Od tej chwili można go rozwiązywać wszędzie tam, gdzie jest potrzebny:

```php
namespace App\Http\Controllers;

use Illuminate\View\View;
use RetJetApi\Returns\Client;

final class ReturnsController extends Controller
{
    public function __construct(private readonly Client $client)
    {
    }

    public function index(): View
    {
        $page = $this->client->rmaRequests()->list(page: (int) request()->query('page', '1'));

        return view('returns.index', [
            'requests' => $page->member(),
            'total' => $page->totalItems(),
        ]);
    }
}
```

## Logowanie, ponawianie i pozostałe ustawienia

Wszystko poza kluczem API przechodzi przez builder, wciąż w obrębie tego samego wiązania:

```php
namespace App\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use RetJetApi\Returns\Client;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Client::class, static fn (): Client => Client::builder()
            ->withApiKey((string) config('services.retjet.key'))
            ->withLogger(Log::channel('retjet'))
            ->withTimeout(30)
            ->build());
    }
}
```

`Log::channel()` zwraca logger zgodny z PSR-3, więc można go podać wprost do `withLogger()`.
Kanał dodaje się w `config/logging.php`:

```php
'channels' => [
    // ...

    'retjet' => [
        'driver' => 'daily',
        'path' => storage_path('logs/retjet.log'),
        'level' => 'debug',
        'days' => 14,
    ],
],
```

SDK nie loguje ciał żądań ani odpowiedzi, więc do tego pliku nie trafiają dane klientów -
patrz [sekcja o logowaniu w README](README.md#logowanie).

## Wstrzyknięcie klienta HTTP Laravela

Fasada `Http` w Laravelu to nakładka na Guzzle'a, a nie klient PSR-18, więc podaj instancję
Guzzle'a wprost. `withHttpClient()` i `withTimeout()` wykluczają się, więc timeout ustawia się
na kliencie:

```php
$this->app->singleton(Client::class, static fn (): Client => Client::builder()
    ->withApiKey((string) config('services.retjet.key'))
    ->withHttpClient(new GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 5]))
    ->build());
```

## Zadania kolejkowe i komendy cykliczne

`iterate()` wykonuje jedno żądanie HTTP na stronę i nigdy nie trzyma całego zbioru wyników w
pamięci - czyli dokładnie to, czego potrzebuje nocna synchronizacja:

```php
foreach ($client->rmaRequests()->iterate() as $rma) {
    ProcessReturn::dispatch($rma->id);
}
```

W zadaniu kolejkowym pamiętaj, że ponawianie blokuje workera: SDK czeka w obrębie jednego
wywołania łącznie do 60 s, zanim się podda. Kiedy rate limit jest prawdopodobny, lepiej zwrócić
zadanie do kolejki niż blokować workera:

```php
use RetJetApi\Returns\Exception\RateLimitException;

public function handle(Client $client): void
{
    try {
        $client->rmaRequests()->changeStatus($this->requestId, 'closed');
    } catch (RateLimitException $e) {
        $this->release($e->retryAfter() ?? 60);
    }
}
```

## Rozwiązywanie problemów

| Objaw | Przyczyna |
|---|---|
| klucz jest `null` po `config:cache` | jest czytany przez `env()` poza `config/`; przenieś go do `config/services.php` |
| `ConfigurationException: A timeout cannot be applied to an HTTP client the SDK did not build` | `withHttpClient()` użyte razem z `withTimeout()` |
| `AuthenticationException` (`401`) | klucz API jest zły lub odwołany - normalny sposób, w jaki to zawodzi |
| `ServerException` z `detail: "Unable to exchange token"` | sama wymiana klucza nie działa, co też może wywołać zły lub odwołany klucz, mimo statusu `5xx` |
| `404` z ciałem HTML | bazowy URI wskazuje host, który nie serwuje API |
