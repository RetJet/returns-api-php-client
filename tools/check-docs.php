<?php

declare(strict_types=1);

/**
 * Verifies that every English document has an equivalent Polish one.
 *
 * Translations drift silently: somebody edits README.md, forgets docs/pl/README.md, and a
 * reader of the Polish version has no way of telling they are looking at an outdated
 * description. This script catches the two kinds of drift that can be detected mechanically:
 *
 *   - **Heading structure.** Both files must have the same sequence of heading levels. Heading
 *     text is translated, so it is not compared; the shape of the document is not, so a section
 *     added on one side and not the other shows up here.
 *   - **Code blocks.** Code is not translated, so the fenced blocks must be byte-identical,
 *     including their language tag and their comments. Any difference is a bug.
 *
 * It also checks that each file links to its sibling, which is the language switcher the
 * convention requires at the top of every page.
 *
 * Layout: README.md, CHANGELOG.md, SECURITY.md and CONTRIBUTING.md live at the repository root
 * in English only - GitHub only recognises SECURITY.md/CONTRIBUTING.md there (or under
 * .github/), and README.md is what it renders as the repo landing page. Every other document
 * has an English copy under docs/en/ and a Polish one under docs/pl/. Both cases pair a file
 * with its Polish translation under docs/pl/ using the *same* filename.
 *
 * Usage: php tools/check-docs.php
 * Exits non-zero on the first divergence found, so it can gate CI.
 */

/** English documents that stay at the repository root; see the Layout note above. */
const ROOT_ENGLISH_DOCS = ['README.md', 'CHANGELOG.md', 'SECURITY.md', 'CONTRIBUTING.md'];

const ENGLISH_DIR = 'docs/en';
const POLISH_DIR = 'docs/pl';

exit(main(dirname(__DIR__)));

function main(string $root): int
{
    $problems = [];
    $pairs = 0;

    foreach (discoverDocuments($root) as $relativePath) {
        $polishRelative = polishCounterpart($relativePath);
        $english = $root . '/' . $relativePath;
        $polish = $root . '/' . $polishRelative;

        if (!is_file($polish)) {
            $problems[] = sprintf('%s has no Polish counterpart (%s).', $relativePath, $polishRelative);

            continue;
        }

        ++$pairs;

        foreach (comparePair($english, $polish, $relativePath, $polishRelative) as $problem) {
            $problems[] = sprintf('%s: %s', $relativePath, $problem);
        }
    }

    foreach (discoverOrphanedTranslations($root) as $orphan) {
        $problems[] = sprintf('%s is a translation with no English original.', $orphan);
    }

    if ($problems !== []) {
        fwrite(STDERR, "Documentation pairs are out of sync:\n\n");

        foreach ($problems as $problem) {
            fwrite(STDERR, '  - ' . $problem . "\n");
        }

        fwrite(STDERR, "\n");

        return 1;
    }

    printf("Documentation is in sync: %d EN/PL %s checked.\n", $pairs, $pairs === 1 ? 'pair' : 'pairs');

    return 0;
}

/**
 * English documents: the root files plus everything under docs/en/.
 *
 * @return list<string>
 */
function discoverDocuments(string $root): array
{
    $documents = [];

    foreach (ROOT_ENGLISH_DOCS as $relativePath) {
        if (is_file($root . '/' . $relativePath)) {
            $documents[] = $relativePath;
        }
    }

    foreach (globRelative($root, ENGLISH_DIR . '/*.md') as $relativePath) {
        $documents[] = $relativePath;
    }

    sort($documents);

    return $documents;
}

/**
 * @return list<string>
 */
function discoverOrphanedTranslations(string $root): array
{
    $orphans = [];

    foreach (globRelative($root, POLISH_DIR . '/*.md') as $relativePath) {
        $original = englishCounterpart($relativePath);

        if (!is_file($root . '/' . $original)) {
            $orphans[] = $relativePath;
        }
    }

    sort($orphans);

    return $orphans;
}

/**
 * @return list<string>
 */
function globRelative(string $root, string $pattern): array
{
    $matches = glob($root . '/' . $pattern);

    if ($matches === false) {
        return [];
    }

    $files = [];

    foreach ($matches as $match) {
        $relativePath = substr($match, strlen($root) + 1);

        if ($relativePath !== '') {
            $files[] = $relativePath;
        }
    }

    return $files;
}

/**
 * Both directions of the pairing share a filename - only the directory changes - since
 * ROOT_ENGLISH_DOCS and docs/en/ both pair against docs/pl/ by basename.
 */
function polishCounterpart(string $relativePath): string
{
    return POLISH_DIR . '/' . basename($relativePath);
}

function englishCounterpart(string $polishRelativePath): string
{
    $name = basename($polishRelativePath);

    return in_array($name, ROOT_ENGLISH_DOCS, true) ? $name : ENGLISH_DIR . '/' . $name;
}

/**
 * @return list<string>
 */
function comparePair(string $english, string $polish, string $englishRelative, string $polishRelative): array
{
    $left = parseMarkdown(read($english));
    $right = parseMarkdown(read($polish));

    return array_merge(
        compareHeadings($left['headings'], $right['headings']),
        compareCodeBlocks($left['blocks'], $right['blocks']),
        compareLanguageSwitcher($english, $polish, $englishRelative, $polishRelative),
    );
}

/**
 * @param list<array{line: int, level: int, text: string}> $english
 * @param list<array{line: int, level: int, text: string}> $polish
 *
 * @return list<string>
 */
function compareHeadings(array $english, array $polish): array
{
    if (count($english) !== count($polish)) {
        return [sprintf(
            'heading count differs - EN has %d, PL has %d.',
            count($english),
            count($polish),
        )];
    }

    $problems = [];

    foreach ($english as $index => $heading) {
        $counterpart = $polish[$index];

        if ($heading['level'] !== $counterpart['level']) {
            $problems[] = sprintf(
                'heading %d is h%d in EN (line %d, "%s") but h%d in PL (line %d, "%s").',
                $index + 1,
                $heading['level'],
                $heading['line'],
                $heading['text'],
                $counterpart['level'],
                $counterpart['line'],
                $counterpart['text'],
            );
        }
    }

    return $problems;
}

/**
 * @param list<array{line: int, info: string, code: string}> $english
 * @param list<array{line: int, info: string, code: string}> $polish
 *
 * @return list<string>
 */
function compareCodeBlocks(array $english, array $polish): array
{
    if (count($english) !== count($polish)) {
        return [sprintf(
            'code block count differs - EN has %d, PL has %d.',
            count($english),
            count($polish),
        )];
    }

    $problems = [];

    foreach ($english as $index => $block) {
        $counterpart = $polish[$index];

        if ($block['info'] !== $counterpart['info']) {
            $problems[] = sprintf(
                'code block %d is tagged "%s" in EN (line %d) but "%s" in PL (line %d).',
                $index + 1,
                $block['info'],
                $block['line'],
                $counterpart['info'],
                $counterpart['line'],
            );

            continue;
        }

        if ($block['code'] !== $counterpart['code']) {
            $problems[] = sprintf(
                'code block %d differs - EN line %d, PL line %d. Code is not translated, so these must match exactly.%s',
                $index + 1,
                $block['line'],
                $counterpart['line'],
                firstDifferingLine($block['code'], $counterpart['code']),
            );
        }
    }

    return $problems;
}

/**
 * The language switcher: each file has to link to the other, so a reader can always get to the
 * version they want.
 *
 * Checking for the sibling's basename used to be enough proof of a real link, back when every
 * pair had distinct names (README.md / README.pl.md). Now that EN and PL share a filename and
 * differ only by docs/en/ vs docs/pl/ (or root vs docs/pl/), that basename appears in both
 * files no matter what - it is the page's own name too. The relative path each side should
 * actually use to reach the other is what is checked instead.
 *
 * @return list<string>
 */
function compareLanguageSwitcher(string $english, string $polish, string $englishRelative, string $polishRelative): array
{
    $problems = [];
    $expectedInEnglish = relativeLink($englishRelative, $polishRelative);
    $expectedInPolish = relativeLink($polishRelative, $englishRelative);

    if (!str_contains(read($english), $expectedInEnglish)) {
        $problems[] = sprintf('the English version does not link to %s (expected "%s").', $polishRelative, $expectedInEnglish);
    }

    if (!str_contains(read($polish), $expectedInPolish)) {
        $problems[] = sprintf('the Polish version does not link to %s (expected "%s").', $englishRelative, $expectedInPolish);
    }

    return $problems;
}

/**
 * The relative path a Markdown link in $fromRelative needs to reach $toRelative - both given
 * as paths relative to the repository root.
 */
function relativeLink(string $fromRelative, string $toRelative): string
{
    $fromDir = dirname($fromRelative);
    $fromParts = $fromDir === '.' ? [] : explode('/', $fromDir);
    $toParts = explode('/', $toRelative);

    while ($fromParts !== [] && $toParts !== [] && $fromParts[0] === $toParts[0]) {
        array_shift($fromParts);
        array_shift($toParts);
    }

    return str_repeat('../', count($fromParts)) . implode('/', $toParts);
}

function firstDifferingLine(string $english, string $polish): string
{
    $left = explode("\n", $english);
    $right = explode("\n", $polish);
    $count = max(count($left), count($right));

    for ($index = 0; $index < $count; ++$index) {
        $a = $left[$index] ?? '(missing)';
        $b = $right[$index] ?? '(missing)';

        if ($a !== $b) {
            return sprintf("\n      EN: %s\n      PL: %s", $a, $b);
        }
    }

    return '';
}

/**
 * Splits a document into its headings and its fenced code blocks.
 *
 * Headings inside a fence are not headings - a shell example may well start a line with `#` -
 * so the fence state is tracked while scanning.
 *
 * @return array{headings: list<array{line: int, level: int, text: string}>, blocks: list<array{line: int, info: string, code: string}>}
 */
function parseMarkdown(string $contents): array
{
    $headings = [];
    $blocks = [];

    $fence = null;
    $fenceInfo = '';
    $fenceLine = 0;
    $collected = [];

    foreach (explode("\n", $contents) as $index => $line) {
        $number = $index + 1;

        if ($fence === null) {
            $opening = matchFence($line);

            if ($opening !== null) {
                $fence = $opening['marker'];
                $fenceInfo = $opening['info'];
                $fenceLine = $number;
                $collected = [];

                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.*?)\s*$/', $line, $matches) === 1) {
                $headings[] = [
                    'line' => $number,
                    'level' => strlen($matches[1]),
                    'text' => $matches[2],
                ];
            }

            continue;
        }

        if (closesFence($line, $fence)) {
            $blocks[] = [
                'line' => $fenceLine,
                'info' => $fenceInfo,
                'code' => implode("\n", $collected),
            ];

            $fence = null;
            $fenceInfo = '';
            $collected = [];

            continue;
        }

        $collected[] = $line;
    }

    return ['headings' => $headings, 'blocks' => $blocks];
}

/**
 * @return array{marker: string, info: string}|null
 */
function matchFence(string $line): ?array
{
    if (preg_match('/^ {0,3}(`{3,}|~{3,})\s*([^`]*)$/', $line, $matches) !== 1) {
        return null;
    }

    return ['marker' => $matches[1], 'info' => trim($matches[2])];
}

function closesFence(string $line, string $fence): bool
{
    $marker = substr($fence, 0, 1);
    $length = strlen($fence);

    return preg_match('/^ {0,3}' . preg_quote($marker, '/') . '{' . $length . ',}\s*$/', $line) === 1;
}

function read(string $path): string
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        fwrite(STDERR, sprintf("Cannot read %s.\n", $path));

        exit(1);
    }

    return $contents;
}
