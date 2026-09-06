<?php

declare(strict_types=1);

/**
 * Fails when an operation in resources/openapi.json has no counterpart in src/Resource.
 *
 * Coverage is read out of the source rather than out of the test suite on purpose: a test can
 * be deleted along with the method it covered and nothing would notice, whereas the spec is
 * the contract the SDK claims to implement.
 *
 * Paths are compared as patterns - every {placeholder}, %d and %s collapses to {} - because
 * the SDK builds action paths through sprintf() while the spec spells the variables out.
 *
 * Usage:
 *   php tools/check-coverage.php            # fail on anything missing
 *   php tools/check-coverage.php --list     # print the full mapping and exit 0
 */

const RESOURCE_DIR = __DIR__ . '/../src/Resource';
const SPEC_PATH = __DIR__ . '/../resources/openapi.json';
const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

/** Maps the AbstractResource helper that issues a request to the verb it sends. */
const VERB_OF_HELPER = [
    'fetch' => 'GET',
    'post' => 'POST',
    'put' => 'PUT',
    'delete' => 'DELETE',
];

function fail(string $message): never
{
    fwrite(STDERR, 'error: ' . $message . PHP_EOL);

    exit(1);
}

/**
 * Collapses every variable part of a path so the spec and the source can be compared.
 */
function pattern(string $path): string
{
    return (string) preg_replace('/\{[^}]*\}|%[ds]/', '{}', $path);
}

/**
 * @return list<string> "METHOD /path" for every operation in the spec
 */
function specOperations(): array
{
    if (!is_file(SPEC_PATH)) {
        fail('resources/openapi.json is missing - run php tools/fetch-openapi.php.');
    }

    try {
        /** @var array<string, mixed> $spec */
        $spec = json_decode((string) file_get_contents(SPEC_PATH), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fail('resources/openapi.json is not valid JSON: ' . $exception->getMessage());
    }

    $paths = $spec['paths'] ?? null;

    if (!is_array($paths)) {
        fail('resources/openapi.json has no "paths".');
    }

    $operations = [];

    foreach ($paths as $path => $item) {
        if (!is_string($path) || !is_array($item)) {
            continue;
        }

        foreach (array_keys($item) as $method) {
            if (is_string($method) && in_array($method, METHODS, true)) {
                $operations[] = strtoupper($method) . ' ' . pattern($path);
            }
        }
    }

    sort($operations);

    return $operations;
}

/**
 * @return array<string, list<string>> "METHOD /path" => resource classes implementing it
 */
function implementedOperations(): array
{
    $files = glob(RESOURCE_DIR . '/*.php');

    if ($files === false || $files === []) {
        fail('No resource classes found in src/Resource.');
    }

    $implemented = [];

    foreach ($files as $file) {
        $class = basename($file, '.php');

        // Defines the helpers rather than calling any endpoint through them.
        if ($class === 'AbstractResource') {
            continue;
        }

        $source = (string) file_get_contents($file);

        foreach (operationsIn($source) as $operation) {
            $implemented[$operation][] = $class;
        }
    }

    return $implemented;
}

/**
 * @return list<string> "METHOD /path" issued by one resource class
 */
function operationsIn(string $source): array
{
    $collectionPath = declaredPath($source, 'collectionPath');
    $itemPath = declaredPath($source, 'itemPath');
    $operations = [];

    // collection()/paginate() read collectionPath(), item() reads itemPath(); none of the
    // three names a path at the call site, so they are resolved from the class instead.
    if ($collectionPath !== null && preg_match('/\$this->(?:collection|paginate)\(/', $source) === 1) {
        $operations[] = 'GET ' . pattern($collectionPath);
    }

    if ($itemPath !== null && str_contains($source, '$this->item(')) {
        $operations[] = 'GET ' . pattern($itemPath);
    }

    $helpers = implode('|', array_keys(VERB_OF_HELPER));
    $found = preg_match_all(
        '/\$this->(' . $helpers . ')\(/',
        $source,
        $matches,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
    );

    if ($found > 0) {
        foreach ($matches as $match) {
            $open = (int) $match[0][1] + strlen((string) $match[0][0]) - 1;
            $argument = firstArgument($source, $open);

            // A path parked in a local first: `$path = sprintf(...); $this->put($path, ...)`.
            if (preg_match('/^\$([A-Za-z_]\w*)$/', $argument, $variable) === 1) {
                $argument = assignedBefore($source, $variable[1], $open) ?? $argument;
            }

            $path = resolvePath($argument, $collectionPath, $itemPath);

            if ($path !== null) {
                $operations[] = VERB_OF_HELPER[(string) $match[1][0]] . ' ' . pattern($path);
            }
        }
    }

    return array_values(array_unique($operations));
}

/**
 * Reads the first argument of a call, starting at its opening parenthesis.
 *
 * Nesting has to be tracked rather than cut at the first comma: every action path in this SDK
 * is built by a nested call - `$this->post($this->action($requestId, 'status'), ...)` - so a
 * naive split stops inside action() and loses the path.
 */
function firstArgument(string $source, int $open): string
{
    $length = strlen($source);
    $depth = 0;
    $quote = null;
    $buffer = '';

    for ($i = $open; $i < $length; ++$i) {
        $char = $source[$i];

        if ($quote !== null) {
            $buffer .= $char;

            if ($char === '\\') {
                $buffer .= $source[$i + 1] ?? '';
                ++$i;
            } elseif ($char === $quote) {
                $quote = null;
            }

            continue;
        }

        if ($char === "'" || $char === '"') {
            $quote = $char;
            $buffer .= $char;

            continue;
        }

        if ($char === '(') {
            ++$depth;

            if ($depth === 1) {
                continue;
            }
        } elseif ($char === ')') {
            --$depth;

            if ($depth === 0) {
                break;
            }
        } elseif ($char === ',' && $depth === 1) {
            break;
        }

        $buffer .= $char;
    }

    return trim($buffer);
}

/**
 * The expression last assigned to $name before $offset, or null when there is none.
 */
function assignedBefore(string $source, string $name, int $offset): ?string
{
    $found = preg_match_all(
        '/\$' . preg_quote($name, '/') . '\s*=\s*(.+?);/s',
        substr($source, 0, $offset),
        $matches,
        PREG_SET_ORDER,
    );

    if ($found < 1) {
        return null;
    }

    /** @var array{0: string, 1: string} $last */
    $last = $matches[$found - 1];

    return trim($last[1]);
}

/**
 * Reads the literal a zero-argument path method returns, e.g. collectionPath().
 */
function declaredPath(string $source, string $method): ?string
{
    $pattern = '/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)\s*:\s*\??string\s*\{\s*return\s+\'([^\']+)\'/';

    return preg_match($pattern, $source, $match) === 1 ? $match[1] : null;
}

/**
 * Turns the first argument of a request helper into a path, or null when it is not one.
 */
function resolvePath(string $expression, ?string $collectionPath, ?string $itemPath): ?string
{
    $expression = trim($expression);

    // A plain literal: '/v1/rma-requests/bulk/star'
    if (preg_match('/^\'(\/v1\/[^\']*)\'/', $expression, $match) === 1) {
        return $match[1];
    }

    // sprintf('/v1/rma-requests/%d/product/%d', ...)
    if (preg_match('/^sprintf\(\s*\'(\/v1\/[^\']*)\'/', $expression, $match) === 1) {
        return $match[1];
    }

    // $this->action($requestId, 'status') - the template lives in action(), the tail here.
    if (preg_match('/^\$this->action\([^,]+,\s*\'([^\']+)\'/', $expression, $match) === 1) {
        return '/v1/rma-requests/{}/' . $match[1];
    }

    if (str_contains($expression, 'collectionPath()')) {
        return $collectionPath;
    }

    if (str_contains($expression, 'itemPath()')) {
        return $itemPath;
    }

    return null;
}

$spec = specOperations();
$implemented = implementedOperations();
$arguments = $_SERVER['argv'] ?? [];
$listOnly = is_array($arguments) && in_array('--list', array_slice($arguments, 1), true);

$missing = [];

foreach ($spec as $operation) {
    if (!array_key_exists($operation, $implemented)) {
        $missing[] = $operation;
    }
}

$undocumented = [];

foreach (array_keys($implemented) as $operation) {
    if (!in_array($operation, $spec, true)) {
        $undocumented[] = $operation;
    }
}

if ($listOnly) {
    foreach ($spec as $operation) {
        $classes = $implemented[$operation] ?? [];
        printf("%-6s %-52s %s%s", ...[...explode(' ', $operation, 2), implode(', ', $classes) ?: 'MISSING', PHP_EOL]);
    }

    exit(0);
}

foreach ($missing as $operation) {
    fwrite(STDERR, 'missing: ' . $operation . PHP_EOL);
}

foreach ($undocumented as $operation) {
    fwrite(STDERR, 'not in the spec: ' . $operation . PHP_EOL);
}

if ($missing !== [] || $undocumented !== []) {
    fail(sprintf(
        '%d of %d spec operations have no resource method, %d resource paths are not in the spec.',
        count($missing),
        count($spec),
        count($undocumented),
    ));
}

printf(
    'All %d spec operations are implemented, and the SDK targets nothing else.%s',
    count($spec),
    PHP_EOL,
);
