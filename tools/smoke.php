<?php

declare(strict_types=1);

/**
 * Exercises the SDK against a live API and answers the questions no unit test can.
 *
 * Never run in CI: it needs a real key and touches real data. Read-only unless --write is
 * passed, and even then it only stars and unstars a request you name yourself.
 *
 * Usage:
 *   RETJET_API_KEY=... php tools/smoke.php
 *   RETJET_API_KEY=... php tools/smoke.php --base-uri=https://api.retjet.com
 *   RETJET_API_KEY=... php tools/smoke.php --request-id=1234 --write
 *
 * The open questions it exists to settle:
 *   1. how large a collection page really is (the spec never says)
 *   2. does a 429 carry Retry-After
 *   3. do items[]/confirmations[]/attachments[] on a live request really match the
 *      RmaRequestItem/RmaRequestFile shapes the spec now declares (fixed from the string[]
 *      it used to claim) - the models trust the spec here, this is what confirms it for real
 *   4. does ConstraintViolation fill `detail` or only `description`
 *   5. does followers/timeline paginate, given neither declares a `page` parameter
 */

require_once __DIR__ . '/../vendor/autoload.php';

use RetJetApi\Returns\Client;
use RetJetApi\Returns\Exception\ApiException;
use RetJetApi\Returns\Exception\RetJetException;
use RetJetApi\Returns\Exception\ValidationException;

/**
 * @param array<string, string> $options
 */
function option(array $options, string $name, ?string $default = null): ?string
{
    $value = $options[$name] ?? $default;

    return $value === '' ? null : $value;
}

function heading(string $text): void
{
    fwrite(STDOUT, PHP_EOL . '== ' . $text . PHP_EOL);
}

function line(string $label, string $value): void
{
    fwrite(STDOUT, sprintf('   %-34s %s%s', $label, $value, PHP_EOL));
}

function describe(mixed $value): string
{
    if (is_array($value)) {
        return sprintf('array(%d) %s', count($value), json_encode(array_slice($value, 0, 3)) ?: '');
    }

    return get_debug_type($value) . ' ' . (json_encode($value) ?: '');
}

$options = [];

foreach (array_slice(is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [], 1) as $argument) {
    if (is_string($argument) && str_starts_with($argument, '--')) {
        $pair = explode('=', substr($argument, 2), 2);
        $options[$pair[0]] = $pair[1] ?? '';
    }
}

$apiKey = getenv('RETJET_API_KEY');

if (!is_string($apiKey) || trim($apiKey) === '') {
    fwrite(STDERR, 'error: set RETJET_API_KEY to a real key first.' . PHP_EOL);

    exit(1);
}

$builder = Client::builder()->withApiKey($apiKey);
$baseUri = option($options, 'base-uri');

if ($baseUri !== null) {
    $builder = $builder->withBaseUri($baseUri);
}

$client = $builder->build();
line('base URI', $client->configuration()->baseUri);

// -------------------------------------------------------------------- reading

heading('Collections: what does one page actually hold? (question 1)');

foreach (['saleChannels', 'returnPoints', 'orderedProducts', 'itemConditions'] as $accessor) {
    try {
        $page = match ($accessor) {
            'saleChannels' => $client->saleChannels()->list(),
            'returnPoints' => $client->returnPoints()->list(),
            'orderedProducts' => $client->orderedProducts()->list(),
            default => $client->itemConditions()->list(),
        };

        line($accessor, sprintf(
            '%d on the page, totalItems=%d',
            count($page),
            $page->totalItems(),
        ));
    } catch (RetJetException $exception) {
        line($accessor, 'FAILED ' . $exception::class . ': ' . $exception->getMessage());
    }
}

heading('RmaRequest: do items[]/confirmations[]/attachments[] match the typed models? (question 3)');

$requestId = option($options, 'request-id');

try {
    $page = $client->rmaRequests()->list();
    line('rma-requests on page', (string) count($page));

    $first = $page->member()[0] ?? null;

    if ($first !== null) {
        $requestId ??= (string) $first->id;
        $raw = $first->raw();

        foreach (['items', 'confirmations', 'attachments', 'customer', 'state', 'saleChannel'] as $field) {
            line($field, describe($raw[$field] ?? null));
        }
    }
} catch (RetJetException $exception) {
    line('rma-requests', 'FAILED ' . $exception::class . ': ' . $exception->getMessage());
}

heading('Sub-collections: do followers/timeline paginate? (question 5)');

if ($requestId === null) {
    line('skipped', 'no request id available; pass --request-id=N');
} else {
    foreach (['followers', 'timeline'] as $accessor) {
        try {
            $collection = $accessor === 'followers'
                ? $client->rmaRequests()->followers((int) $requestId)
                : $client->rmaRequests()->timeline((int) $requestId);

            line($accessor, sprintf(
                '%d on the page, totalItems=%d, view=%s',
                count($collection),
                $collection->totalItems(),
                json_encode($collection->view()) ?: 'absent',
            ));
        } catch (RetJetException $exception) {
            line($accessor, 'FAILED ' . $exception::class . ': ' . $exception->getMessage());
        }
    }
}

// -------------------------------------------------------------------- errors

heading('Bad key: is it really 500 "Unable to exchange token" rather than 401?');

$wrongKey = Client::builder()->withApiKey('definitely-not-a-valid-key');

if ($baseUri !== null) {
    $wrongKey = $wrongKey->withBaseUri($baseUri);
}

try {
    $wrongKey->build()->saleChannels()->list();
    line('result', 'no exception - the quirk is gone, update the README');
} catch (ApiException $exception) {
    line('class', $exception::class);
    line('status', (string) $exception->status());
    line('problem detail', $exception->problem()->detail() ?? 'null');
}

heading('Timeout: does it actually cut the connection?');

$impatient = Client::builder()->withApiKey($apiKey)->withTimeout(1);

if ($baseUri !== null) {
    $impatient = $impatient->withBaseUri($baseUri);
}

try {
    $impatient->build()->rmaRequests()->list();
    line('1s timeout', 'the request completed inside a second - inconclusive');
} catch (RetJetException $exception) {
    line('1s timeout', $exception::class . ': ' . $exception->getMessage());
}

// -------------------------------------------------------------------- writing

if (!array_key_exists('write', $options)) {
    heading('Writes skipped - pass --write --request-id=N to exercise them');

    exit(0);
}

if ($requestId === null) {
    fwrite(STDERR, 'error: --write needs --request-id=N.' . PHP_EOL);

    exit(1);
}

heading('Validation: does ConstraintViolation fill `detail`? (question 4)');

// A write, hence behind --write: an empty state identifier should be rejected, but "should"
// is exactly what this script exists to check.
try {
    $client->rmaRequests()->changeStatus((int) $requestId, '');
    line('result', 'NO EXCEPTION - an empty state identifier was accepted, check the request');
} catch (ValidationException $exception) {
    line('violations', json_encode($exception->violations()) ?: '');
    line('problem detail', $exception->problem()->detail() ?? 'null (only `description` filled?)');
} catch (RetJetException $exception) {
    line('other failure', $exception::class . ': ' . $exception->getMessage());
}

heading(sprintf('Writes on request %s: star, then unstar', $requestId));

try {
    $client->rmaRequests()->star((int) $requestId);
    line('star', 'accepted');
    $client->rmaRequests()->unstar((int) $requestId);
    line('unstar', 'accepted');
} catch (RetJetException $exception) {
    line('FAILED', $exception::class . ': ' . $exception->getMessage());
}

fwrite(STDOUT, PHP_EOL . 'Record the answers against the open questions above.' . PHP_EOL);
