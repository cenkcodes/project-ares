#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\VideoTaxonomyResolver;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

function fail(string $message): never
{
    fwrite(STDERR, 'ERROR=' . $message . PHP_EOL);
    exit(1);
}

$options = getopt('', ['backend::', 'input:']);
$backend = isset($options['backend'])
    ? rtrim((string) $options['backend'], DIRECTORY_SEPARATOR)
    : '/var/www/project-ares/backend';
$input = isset($options['input']) ? (string) $options['input'] : '';

if ($input === '' || ! is_file($input)) {
    fail('Input source-term CSV is missing.');
}

$autoload = $backend . '/vendor/autoload.php';
$bootstrap = $backend . '/bootstrap/app.php';

if (! is_file($autoload) || ! is_file($bootstrap)) {
    fail('Laravel bootstrap files are missing.');
}

require $autoload;
$app = require $bootstrap;
$app->make(ConsoleKernel::class)->bootstrap();
$resolver = $app->make(VideoTaxonomyResolver::class);

$handle = fopen($input, 'rb');
if ($handle === false) {
    fail('Could not open source-term CSV.');
}

$header = fgetcsv($handle, null, ',', '"', '\\');
if ($header === false) {
    fclose($handle);
    fail('Source-term CSV is empty.');
}
if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? (string) $header[0];
}

$expected = ['video_slug', 'video_source', 'term_type', 'term', 'normalized_term'];
if ($header !== $expected) {
    fclose($handle);
    fail('Unexpected source-term headers.');
}

$rows = 0;
$nonAscii = 0;
$mismatches = [];

while (($values = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
    if ($values === [null] || $values === []) {
        continue;
    }
    $rows++;
    if (count($values) !== count($header)) {
        fclose($handle);
        fail('Invalid column count at row ' . $rows . '.');
    }
    $row = array_combine($header, $values);
    if ($row === false) {
        fclose($handle);
        fail('Could not combine row ' . $rows . '.');
    }

    $term = (string) $row['term'];
    $normalized = (string) $row['normalized_term'];
    if (preg_match('/[^\x00-\x7F]/', $term) === 1) {
        $nonAscii++;
    }

    $recomputed = $resolver->normalizeTerm($term);
    if ($recomputed !== $normalized) {
        $mismatches[] = [
            (string) $row['video_slug'],
            $term,
            $normalized,
            $recomputed,
        ];
    }
}

fclose($handle);

echo 'VERIFY_ROWS=' . $rows . PHP_EOL;
echo 'VERIFY_NON_ASCII_TERMS=' . $nonAscii . PHP_EOL;
echo 'VERIFY_NORMALIZATION_MISMATCHES=' . count($mismatches) . PHP_EOL;

foreach (array_slice($mismatches, 0, 20) as $mismatch) {
    echo 'VERIFY_MISMATCH=' . implode('|', $mismatch) . PHP_EOL;
}

if ($rows < 1) {
    fail('No source-term rows were produced.');
}
if ($nonAscii !== 0) {
    fail('Non-ASCII source terms remain in Eporner sidecar.');
}
if ($mismatches !== []) {
    fail('Laravel normalization mismatches remain.');
}

echo 'EPORNER_SOURCE_TERM_NORMALIZATION_VERIFY=PASS' . PHP_EOL;
