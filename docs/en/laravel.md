# Laravel integration

**English** · [Polski](../pl/laravel.md)

The package ships no service provider, no facade and no config file to publish. Wiring it up
is a few lines in the application's own provider, which keeps the SDK free of a Laravel
version matrix it would otherwise have to track.

Guzzle is present in every Laravel skeleton, so `php-http/discovery` resolves an HTTP client
without any extra installation.

## Minimal setup

Add the key to `.env`:

```dotenv
RETJET_API_KEY=your-api-key
```

Add it to `config/services.php`, so it survives `php artisan config:cache`:

```php
return [
    // ...

    'retjet' => [
        'key' => env('RETJET_API_KEY'),
    ],
];
```

Bind the client in `app/Providers/AppServiceProvider.php`:

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

Now resolve it wherever you need it:

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

## Logging, retries and other settings

Everything beyond the API key goes through the builder, still inside the same binding:

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

`Log::channel()` returns a PSR-3 logger, so it can be handed to `withLogger()` directly. Add
the channel in `config/logging.php`:

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

The SDK never logs request or response bodies, so no customer data reaches that file - see the
[logging section of the README](../../README.md#logging).

## Injecting Laravel's own HTTP client

Laravel's `Http` facade is a wrapper around Guzzle rather than a PSR-18 client, so pass a
Guzzle instance directly. `withHttpClient()` and `withTimeout()` are mutually exclusive, so the
timeout belongs on the client:

```php
$this->app->singleton(Client::class, static fn (): Client => Client::builder()
    ->withApiKey((string) config('services.retjet.key'))
    ->withHttpClient(new GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 5]))
    ->build());
```

## Queued jobs and scheduled commands

`iterate()` performs one HTTP request per page and never holds the whole result set in memory,
which is what a nightly sync wants:

```php
foreach ($client->rmaRequests()->iterate() as $rma) {
    ProcessReturn::dispatch($rma->id);
}
```

Inside a queued job, remember that retrying blocks the worker: the SDK waits up to 60 s in
total within a single call before giving up. When a rate limit is likely, prefer releasing the
job back to the queue over blocking a worker:

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

## Troubleshooting

| Symptom | Cause |
|---|---|
| the key is `null` after `config:cache` | it is read with `env()` outside `config/`; move it into `config/services.php` |
| `ConfigurationException: A timeout cannot be applied to an HTTP client the SDK did not build` | `withHttpClient()` and `withTimeout()` used together |
| `AuthenticationException` (`401`) | the API key is wrong or revoked - the normal way this fails |
| `ServerException` with `detail: "Unable to exchange token"` | the key exchange itself is unhealthy, and can be triggered by a wrong or revoked key too, despite the `5xx` status |
| `404` with an HTML body | the base URI points at a host that does not serve the API |
