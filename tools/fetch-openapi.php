<?php

declare(strict_types=1);

/**
 * Downloads the RetJet API OpenAPI specification and freezes it in resources/openapi.json.
 *
 * The spec is NOT served at /docs.json - that returns 404 "Format \"json\" is not supported".
 * The only working way is an Accept: application/vnd.openapi+json request against /docs.
 *
 * Note that the production host (api.retjet.com) does not expose /docs at all, so there is
 * no usable default here - point --base-uri (or RETJET_SPEC_BASE_URI) at an environment that
 * does serve /docs. This is deliberate and unrelated to the SDK's own default base URI.
 *
 * Usage:
 *   php tools/fetch-openapi.php --base-uri=https://...  # refresh resources/openapi.json
 *   php tools/fetch-openapi.php --base-uri=https://... --check # fail if the frozen copy is stale
 *   php tools/fetch-openapi.php --base-uri=https://... --output=path.json
 */

const ACCEPT_HEADER = 'application/vnd.openapi+json';
const TIMEOUT_SECONDS = 30;

/**
 * @param array<array-key, mixed> $argv
 * @return array<string, string>
 */
function parseOptions(array $argv): array
{
    $options = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (!is_string($argument) || !str_starts_with($argument, '--')) {
            fail('Arguments must be of the form --name or --name=value.');
        }

        $pair = explode('=', substr($argument, 2), 2);
        $options[$pair[0]] = $pair[1] ?? '';
    }

    return $options;
}

function fail(string $message): never
{
    fwrite(STDERR, 'error: ' . $message . PHP_EOL);

    exit(1);
}

function fetch(string $url): string
{
    if (extension_loaded('curl')) {
        return fetchWithCurl($url);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'Accept: ' . ACCEPT_HEADER . "\r\n",
            'timeout' => TIMEOUT_SECONDS,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);

    if ($body === false) {
        fail(sprintf('Could not reach %s.', $url));
    }

    $statusLine = $http_response_header[0] ?? '';
    $status = (int) (explode(' ', $statusLine)[1] ?? 0);
    assertOk($status, $url);

    return $body;
}

function fetchWithCurl(string $url): string
{
    $handle = curl_init($url);

    if ($handle === false) {
        fail('Could not initialise cURL.');
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => TIMEOUT_SECONDS,
        CURLOPT_HTTPHEADER => ['Accept: ' . ACCEPT_HEADER],
    ]);

    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);

    if (!is_string($body)) {
        fail(sprintf('Could not reach %s: %s', $url, $error));
    }

    assertOk($status, $url);

    return $body;
}

function assertOk(int $status, string $url): void
{
    if ($status !== 200) {
        fail(sprintf(
            'GET %s returned HTTP %d. The production host does not serve /docs - use --base-uri to point at an environment that does.',
            $url,
            $status,
        ));
    }
}

/**
 * Normalises formatting so a re-fetch of an unchanged spec produces a byte-identical file.
 */
function normalise(string $json, string $url): string
{
    try {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fail(sprintf('%s did not return valid JSON: %s', $url, $exception->getMessage()));
    }

    if (!isset($decoded['paths']) || !is_array($decoded['paths'])) {
        fail('The downloaded document has no "paths" - this does not look like an OpenAPI spec.');
    }

    return json_encode(
        $decoded,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . PHP_EOL;
}

/**
 * @param array<string, mixed> $spec
 */
function countOperations(array $spec): int
{
    $methods = ['get', 'post', 'put', 'patch', 'delete'];
    $count = 0;

    /** @var array<string, mixed> $paths */
    $paths = $spec['paths'] ?? [];

    foreach ($paths as $operations) {
        if (!is_array($operations)) {
            continue;
        }

        $count += count(array_intersect(array_keys($operations), $methods));
    }

    return $count;
}

$arguments = $_SERVER['argv'] ?? [];
$options = parseOptions(is_array($arguments) ? $arguments : []);
$rawBaseUri = $options['base-uri'] ?? (getenv('RETJET_SPEC_BASE_URI') ?: null);

if ($rawBaseUri === null) {
    fail('No --base-uri given and RETJET_SPEC_BASE_URI is not set. The production host does '
        . 'not serve /docs, so an environment that does must be provided explicitly.');
}

$baseUri = rtrim($rawBaseUri, '/');
$output = $options['output'] ?? dirname(__DIR__) . '/resources/openapi.json';
$checkOnly = array_key_exists('check', $options);
$url = $baseUri . '/docs';

fwrite(STDOUT, sprintf('Fetching %s ...%s', $url, PHP_EOL));

$spec = normalise(fetch($url), $url);

/** @var array<string, mixed> $decoded */
$decoded = json_decode($spec, true, 512, JSON_THROW_ON_ERROR);
$operations = countOperations($decoded);
$paths = is_array($decoded['paths'] ?? null) ? count($decoded['paths']) : 0;

if ($checkOnly) {
    $frozen = is_file($output) ? (string) file_get_contents($output) : '';

    if ($frozen !== $spec) {
        fail(sprintf('%s is out of date - run "php tools/fetch-openapi.php" and commit the result.', $output));
    }

    fwrite(STDOUT, sprintf('Up to date: %d operations across %d paths.%s', $operations, $paths, PHP_EOL));

    exit(0);
}

if (!is_dir(dirname($output)) && !mkdir(dirname($output), 0o755, true)) {
    fail(sprintf('Could not create %s.', dirname($output)));
}

if (file_put_contents($output, $spec) === false) {
    fail(sprintf('Could not write %s.', $output));
}

fwrite(STDOUT, sprintf(
    'Wrote %s - %d operations across %d paths, %s.%s',
    $output,
    $operations,
    $paths,
    number_format(strlen($spec) / 1024, 1) . ' KiB',
    PHP_EOL,
));
