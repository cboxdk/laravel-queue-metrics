<?php

declare(strict_types=1);

/**
 * Supply-chain licence gate.
 *
 * Reads composer.lock and fails (non-zero exit) when any dependency - runtime or
 * dev - is not offered under a permissive licence. Self-contained on purpose: no
 * Composer plugins, no autoloader, no network access, so it runs identically on a
 * developer machine and on a locked-down CI runner.
 *
 * Usage: php bin/check-licenses.php
 */

/**
 * Licences considered permissive for this package.
 *
 * Weak-copyleft licences (LGPL-*, MPL-*, EPL-*) and strong copyleft (GPL-*,
 * AGPL-*) are deliberately absent: they impose relicensing or source-disclosure
 * obligations on consumers of an MIT-licensed library.
 *
 * @var list<string>
 */
const PERMISSIVE_LICENSES = [
    '0BSD',
    'APACHE-2.0',
    'BSD-2-CLAUSE',
    'BSD-3-CLAUSE',
    'ISC',
    'MIT',
    'UNLICENSE',
    'WTFPL',
];

/**
 * Reviewed, deliberate exceptions keyed by Composer package name.
 *
 * Every entry MUST carry a written justification explaining why the
 * non-permissive licence is acceptable for this package's distribution model.
 * Blanket entries ("needed for CI") are not justifications - if a dependency
 * genuinely cannot be licensed permissively, replace the dependency instead.
 *
 * Currently empty: every package in composer.lock is offered under a permissive
 * licence, so no exception is warranted.
 *
 * @var array<string, string>
 */
const LICENSE_EXCEPTIONS = [
    // 'vendor/package' => 'Why this non-permissive licence is acceptable here.',
];

/**
 * @return array{packages: list<array<string, mixed>>, packages-dev: list<array<string, mixed>>}
 */
function loadLockFile(string $path): array
{
    if (! is_file($path)) {
        fwrite(STDERR, "composer.lock not found at {$path}\n");
        exit(1);
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
        fwrite(STDERR, "composer.lock could not be read at {$path}\n");
        exit(1);
    }

    $lock = json_decode($contents, true);

    if (! is_array($lock)) {
        fwrite(STDERR, "composer.lock is not valid JSON at {$path}\n");
        exit(1);
    }

    return [
        'packages' => array_values((array) ($lock['packages'] ?? [])),
        'packages-dev' => array_values((array) ($lock['packages-dev'] ?? [])),
    ];
}

function normaliseLicenseId(string $license): string
{
    return strtoupper(rtrim(trim($license), '+'));
}

function isPermissiveLicenseId(string $license): bool
{
    return in_array(normaliseLicenseId($license), PERMISSIVE_LICENSES, true);
}

/**
 * @return list<string>
 */
function tokeniseExpression(string $expression): array
{
    $spaced = str_replace(['(', ')'], [' ( ', ' ) '], $expression);
    $tokens = preg_split('/\s+/', trim($spaced), -1, PREG_SPLIT_NO_EMPTY);

    return $tokens === false ? [] : $tokens;
}

/**
 * Evaluates a single SPDX licence expression, honouring OR/AND/WITH precedence
 * and parentheses. A dual-licensed package such as
 * "BSD-3-Clause OR GPL-3.0-only" passes because one of the offered licences is
 * permissive - the consumer picks the permissive branch.
 *
 * @param  list<string>  $tokens
 */
function evaluateExpression(array $tokens, int &$cursor): bool
{
    $result = evaluateConjunction($tokens, $cursor);

    while (isset($tokens[$cursor]) && strtoupper($tokens[$cursor]) === 'OR') {
        $cursor++;
        $right = evaluateConjunction($tokens, $cursor);
        $result = $result || $right;
    }

    return $result;
}

/**
 * @param  list<string>  $tokens
 */
function evaluateConjunction(array $tokens, int &$cursor): bool
{
    $result = evaluateTerm($tokens, $cursor);

    while (isset($tokens[$cursor]) && strtoupper($tokens[$cursor]) === 'AND') {
        $cursor++;
        $right = evaluateTerm($tokens, $cursor);
        $result = $result && $right;
    }

    return $result;
}

/**
 * @param  list<string>  $tokens
 */
function evaluateTerm(array $tokens, int &$cursor): bool
{
    if (! isset($tokens[$cursor])) {
        return false;
    }

    if ($tokens[$cursor] === '(') {
        $cursor++;
        $result = evaluateExpression($tokens, $cursor);

        if (isset($tokens[$cursor]) && $tokens[$cursor] === ')') {
            $cursor++;
        }

        return $result;
    }

    $result = isPermissiveLicenseId($tokens[$cursor]);
    $cursor++;

    // "MIT WITH Some-Exception" - the exception narrows the licence, it never
    // makes a copyleft licence permissive, so the base licence decides.
    if (isset($tokens[$cursor]) && strtoupper($tokens[$cursor]) === 'WITH') {
        $cursor += 2;
    }

    return $result;
}

function isPermissiveExpression(string $expression): bool
{
    $tokens = tokeniseExpression($expression);

    if ($tokens === []) {
        return false;
    }

    $cursor = 0;

    return evaluateExpression($tokens, $cursor);
}

/**
 * Composer allows both the array form (["MIT", "GPL-2.0-only"]) and the string
 * form ("MIT OR GPL-2.0-only"). The array form is a disjunction as well, so a
 * package passes when ANY offered licence is permissive.
 *
 * @param  array<string, mixed>  $package
 * @return list<string>
 */
function declaredLicenses(array $package): array
{
    $license = $package['license'] ?? [];

    if (is_string($license)) {
        $license = [$license];
    }

    if (! is_array($license)) {
        return [];
    }

    $licenses = [];

    foreach ($license as $entry) {
        if (is_string($entry) && trim($entry) !== '') {
            $licenses[] = trim($entry);
        }
    }

    return $licenses;
}

/**
 * @param  list<string>  $licenses
 */
function isPermissivePackage(array $licenses): bool
{
    foreach ($licenses as $license) {
        if (isPermissiveExpression($license)) {
            return true;
        }
    }

    return false;
}

/**
 * @param  list<array{package: string, section: string, licenses: string, reason: string}>  $rows
 */
function printFailureTable(array $rows): void
{
    $headers = ['Package', 'Section', 'Licence(s)', 'Reason'];
    $widths = array_map(strlen(...), $headers);

    foreach ($rows as $row) {
        $widths[0] = max($widths[0], strlen($row['package']));
        $widths[1] = max($widths[1], strlen($row['section']));
        $widths[2] = max($widths[2], strlen($row['licenses']));
        $widths[3] = max($widths[3], strlen($row['reason']));
    }

    $separator = '+'.implode('+', array_map(static fn (int $width): string => str_repeat('-', $width + 2), $widths)).'+';

    $line = static function (array $cells) use ($widths): string {
        $padded = [];

        foreach (array_values($cells) as $index => $cell) {
            $padded[] = ' '.str_pad((string) $cell, $widths[$index]).' ';
        }

        return '|'.implode('|', $padded).'|';
    };

    fwrite(STDERR, $separator."\n");
    fwrite(STDERR, $line($headers)."\n");
    fwrite(STDERR, $separator."\n");

    foreach ($rows as $row) {
        fwrite(STDERR, $line([$row['package'], $row['section'], $row['licenses'], $row['reason']])."\n");
    }

    fwrite(STDERR, $separator."\n");
}

$rootPath = dirname(__DIR__);
$lock = loadLockFile($rootPath.'/composer.lock');

$sections = [
    'packages' => 'require',
    'packages-dev' => 'require-dev',
];

/** @var list<array{package: string, section: string, licenses: string, reason: string}> $failures */
$failures = [];
$allowed = [];
$checked = 0;

foreach ($sections as $key => $label) {
    foreach ($lock[$key] as $package) {
        $name = is_string($package['name'] ?? null) ? $package['name'] : '(unnamed package)';
        $licenses = declaredLicenses($package);
        $checked++;

        if (isPermissivePackage($licenses)) {
            continue;
        }

        if (array_key_exists($name, LICENSE_EXCEPTIONS)) {
            $allowed[] = $name.' ('.($licenses === [] ? 'none declared' : implode(', ', $licenses)).'): '.LICENSE_EXCEPTIONS[$name];

            continue;
        }

        $failures[] = [
            'package' => $name,
            'section' => $label,
            'licenses' => $licenses === [] ? '(none declared)' : implode(' OR ', $licenses),
            'reason' => $licenses === []
                ? 'No licence declared in composer.lock'
                : 'No permissive licence offered',
        ];
    }
}

echo "Licence check: {$checked} packages inspected against ".count(PERMISSIVE_LICENSES)." permissive licences.\n";

foreach ($allowed as $exception) {
    echo "  allowed exception: {$exception}\n";
}

if ($failures !== []) {
    fwrite(STDERR, "\n".count($failures)." package(s) are not offered under a permissive licence:\n\n");
    printFailureTable($failures);
    fwrite(STDERR, "\nPermissive licences: ".implode(', ', PERMISSIVE_LICENSES)."\n");
    fwrite(STDERR, "Replace the dependency, or add a reviewed entry with a written justification to LICENSE_EXCEPTIONS in bin/check-licenses.php.\n");

    exit(1);
}

echo "All dependencies are offered under a permissive licence.\n";

exit(0);
