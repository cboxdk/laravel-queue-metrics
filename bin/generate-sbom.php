<?php

declare(strict_types=1);

/**
 * Deterministic CycloneDX 1.5 SBOM generator.
 *
 * Emits sbom.json straight from composer.lock. The output is byte-for-byte
 * reproducible: components are sorted by name, the serial number is derived from
 * a hash of the component set, and no wall-clock timestamp is written. The
 * committed sbom.json therefore changes only when dependencies change, which is
 * what makes the CI drift check ("regenerate and git diff --exit-code") meaningful.
 *
 * Self-contained: no Composer plugins, no autoloader, no network access.
 *
 * Usage: php bin/generate-sbom.php
 */
const SPEC_VERSION = '1.5';

/**
 * @return array<string, mixed>
 */
function readJsonFile(string $path): array
{
    if (! is_file($path)) {
        fwrite(STDERR, "File not found: {$path}\n");
        exit(1);
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
        fwrite(STDERR, "File could not be read: {$path}\n");
        exit(1);
    }

    $decoded = json_decode($contents, true);

    if (! is_array($decoded)) {
        fwrite(STDERR, "File is not valid JSON: {$path}\n");
        exit(1);
    }

    return $decoded;
}

/**
 * Composer allows both the array form (["MIT"]) and the string form ("MIT").
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
 * CycloneDX accepts either a list of licence objects or a single SPDX
 * expression. Entries containing whitespace are compound expressions
 * ("BSD-3-Clause OR GPL-3.0-only") and are emitted as such; bare identifiers are
 * emitted as SPDX ids.
 *
 * @param  list<string>  $licenses
 * @return list<array<string, mixed>>
 */
function formatLicenses(array $licenses): array
{
    if ($licenses === []) {
        return [];
    }

    if (count($licenses) === 1 && preg_match('/\s/', $licenses[0]) === 1) {
        return [['expression' => $licenses[0]]];
    }

    $formatted = [];

    foreach ($licenses as $license) {
        $formatted[] = preg_match('/\s/', $license) === 1
            ? ['license' => ['name' => $license]]
            : ['license' => ['id' => $license]];
    }

    return $formatted;
}

function packageUrl(string $name, string $version): string
{
    $segments = explode('/', $name);
    $encoded = implode('/', array_map(rawurlencode(...), $segments));

    return 'pkg:composer/'.$encoded.'@'.rawurlencode($version);
}

/**
 * @param  array<string, mixed>  $package
 * @return array<string, mixed>
 */
function buildComponent(array $package, bool $isDevRequirement): array
{
    $name = is_string($package['name'] ?? null) ? $package['name'] : '';
    $version = is_string($package['version'] ?? null) ? $package['version'] : '0.0.0';
    $purl = packageUrl($name, $version);

    $component = [
        'type' => 'library',
        'bom-ref' => $purl,
        'name' => $name,
        'version' => $version,
    ];

    if (is_string($package['description'] ?? null) && $package['description'] !== '') {
        $component['description'] = $package['description'];
    }

    $licenses = formatLicenses(declaredLicenses($package));

    if ($licenses !== []) {
        $component['licenses'] = $licenses;
    }

    $component['purl'] = $purl;

    $shasum = $package['dist']['shasum'] ?? null;

    if (is_string($shasum) && preg_match('/^[0-9a-f]{40}$/i', $shasum) === 1) {
        $component['hashes'] = [['alg' => 'SHA-1', 'content' => strtolower($shasum)]];
    }

    $externalReferences = [];

    if (is_string($package['source']['url'] ?? null)) {
        $externalReferences[] = ['type' => 'vcs', 'url' => $package['source']['url']];
    }

    if (is_string($package['dist']['url'] ?? null)) {
        $externalReferences[] = ['type' => 'distribution', 'url' => $package['dist']['url']];
    }

    if ($externalReferences !== []) {
        $component['externalReferences'] = $externalReferences;
    }

    $component['properties'] = [
        [
            'name' => 'cdx:composer:package:isDevRequirement',
            'value' => $isDevRequirement ? 'true' : 'false',
        ],
    ];

    return $component;
}

/**
 * Formats a content hash as a syntactically valid RFC 4122 UUID (version 5,
 * variant 10x). Two runs over the same dependency set produce the same serial
 * number; any change to the component set produces a different one.
 */
function contentDerivedUuid(string $content): string
{
    $hash = hash('sha256', $content);
    $hex = substr($hash, 0, 32);

    $hex[12] = '5';
    $hex[16] = dechex(8 | (hexdec($hex[16]) & 3));

    return implode('-', [
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12),
    ]);
}

$rootPath = dirname(__DIR__);
$composer = readJsonFile($rootPath.'/composer.json');
$lock = readJsonFile($rootPath.'/composer.lock');

$components = [];

foreach (['packages' => false, 'packages-dev' => true] as $section => $isDevRequirement) {
    foreach ((array) ($lock[$section] ?? []) as $package) {
        if (! is_array($package)) {
            continue;
        }

        $components[] = buildComponent($package, $isDevRequirement);
    }
}

usort($components, static fn (array $a, array $b): int => [$a['name'], $a['version']] <=> [$b['name'], $b['version']]);

$rootName = is_string($composer['name'] ?? null) ? $composer['name'] : 'root-package';
// The root package is unversioned in composer.json - git tags are the source of
// truth for releases. Deriving the version from git would make the SBOM change
// on every tag and break the drift check, so an explicit placeholder is used.
$rootVersion = is_string($composer['version'] ?? null) ? $composer['version'] : '0.0.0';
$rootPurl = packageUrl($rootName, $rootVersion);

$rootComponent = [
    'type' => 'library',
    'bom-ref' => $rootPurl,
    'name' => $rootName,
    'version' => $rootVersion,
];

if (is_string($composer['description'] ?? null) && $composer['description'] !== '') {
    $rootComponent['description'] = $composer['description'];
}

$rootLicenses = formatLicenses(declaredLicenses($composer));

if ($rootLicenses !== []) {
    $rootComponent['licenses'] = $rootLicenses;
}

$rootComponent['purl'] = $rootPurl;

$serialSource = json_encode(['metadata' => $rootComponent, 'components' => $components], JSON_THROW_ON_ERROR);

$bom = [
    'bomFormat' => 'CycloneDX',
    'specVersion' => SPEC_VERSION,
    'serialNumber' => 'urn:uuid:'.contentDerivedUuid($serialSource),
    'version' => 1,
    'metadata' => [
        // No timestamp: a wall-clock value would change on every run and defeat
        // the SBOM drift check in CI.
        'tools' => [
            [
                'vendor' => 'Cbox',
                'name' => 'bin/generate-sbom.php',
            ],
        ],
        'component' => $rootComponent,
    ],
    'components' => $components,
];

$json = json_encode($bom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

$target = $rootPath.'/sbom.json';

if (file_put_contents($target, $json) === false) {
    fwrite(STDERR, "sbom.json could not be written to {$target}\n");
    exit(1);
}

echo 'Wrote sbom.json: '.count($components)." components, serial {$bom['serialNumber']}.\n";

exit(0);
