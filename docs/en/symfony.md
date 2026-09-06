# Symfony integration

**English** · [Polski](../pl/symfony.md)

The package ships no bundle. Wiring it up is a few lines of `services.yaml`, and that is
deliberate: a bundle would add a release to maintain and a version matrix to track without
saving anyone meaningful work.

## Minimal setup

Put the key in `.env` (and the real value in `.env.local`, which is not committed):

```dotenv
RETJET_API_KEY=your-api-key
```

Register the client as a service in `config/services.yaml`:

```yaml
RetJetApi\Returns\Client:
    factory: ['RetJetApi\Returns\Client', 'create']
    arguments: ['%env(RETJET_API_KEY)%']
```

That is all. `php-http/discovery` picks up `symfony/http-client` automatically if the project
has it; if it does not, install one:

```bash
composer require symfony/http-client nyholm/psr7
```

Now autowire `Client` anywhere:

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

## Logging, retries and other settings

Anything beyond the API key goes through the builder, which is still one service definition:

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

To get a dedicated Monolog channel, declare it in `config/packages/monolog.yaml`:

```yaml
monolog:
    channels: ['retjet']
```

The SDK logs one record per HTTP attempt at `debug` and one per failure at `warning`. Bodies
are never logged, so no personal data reaches the channel - see the
[logging section of the README](../../README.md#logging).

## Injecting a specific HTTP client

When the project has several PSR-18 implementations, or when the HTTP client needs options
discovery cannot guess, pass it in. Remember that `withHttpClient()` and `withTimeout()` are
mutually exclusive - configure the timeout on the client itself:

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

## Using resources directly

If a service only needs one resource, inject that instead of the whole client:

```yaml
RetJetApi\Returns\Resource\RmaRequests:
    factory: ['@RetJetApi\Returns\Client', 'rmaRequests']
```

## Console commands and long-running workers

`iterate()` fetches one page per HTTP request and never holds the whole result set in memory,
which is what you want in a command that walks every request:

```php
foreach ($client->rmaRequests()->iterate() as $rma) {
    $output->writeln($rma->identifier ?? '(no identifier)');
}
```

In a Messenger worker, keep in mind that retrying blocks the process: the SDK waits up to 60 s
in total inside a single call before giving up. If that is too long for your worker, lower it
with `withRetry()` or handle `RateLimitException` yourself and requeue the message using
`retryAfter()`.

## Troubleshooting

| Symptom | Cause |
|---|---|
| `ConfigurationException: No PSR-18 HTTP client could be discovered` | no PSR-18 implementation installed - add `symfony/http-client` and `nyholm/psr7` |
| `ConfigurationException: A timeout cannot be applied to an HTTP client the SDK did not build` | `withHttpClient()` and `withTimeout()` used together |
| `AuthenticationException` (`401`) | the API key is wrong or revoked - the normal way this fails |
| `ServerException` with `detail: "Unable to exchange token"` | the key exchange itself is unhealthy, and can be triggered by a wrong or revoked key too, despite the `5xx` status |
| `404` with an HTML body | the base URI points at a host that does not serve the API |
