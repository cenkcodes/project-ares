#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Models\Video;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, 'ERROR=' . $message . PHP_EOL);
    exit($code);
}

$options = getopt('', [
    'backend::',
    'backup:',
]);

$backend = isset($options['backend'])
    ? rtrim((string) $options['backend'], DIRECTORY_SEPARATOR)
    : '/var/www/project-ares/backend';

$backupPath = isset($options['backup'])
    ? (string) $options['backup']
    : '';

if ($backupPath === '') {
    fail('--backup is required.');
}

$autoload = $backend . '/vendor/autoload.php';
$bootstrap = $backend . '/bootstrap/app.php';

if (! is_file($autoload)) {
    fail('Laravel autoload not found: ' . $autoload);
}

if (! is_file($bootstrap)) {
    fail('Laravel bootstrap not found: ' . $bootstrap);
}

require $autoload;
$app = require $bootstrap;
$app->make(Kernel::class)->bootstrap();

$videos = Video::query()
    ->where('video_source', 'eporner')
    ->orderBy('id')
    ->get(['id', 'slug', 'views']);

$backupDir = dirname($backupPath);
if (! is_dir($backupDir) && ! mkdir($backupDir, 0775, true) && ! is_dir($backupDir)) {
    fail('Unable to create backup directory: ' . $backupDir);
}

$handle = fopen($backupPath, 'wb');
if ($handle === false) {
    fail('Unable to create view backup CSV: ' . $backupPath);
}

fputcsv($handle, ['id', 'slug', 'views']);
foreach ($videos as $video) {
    fputcsv($handle, [
        (string) $video->id,
        (string) $video->slug,
        (string) ((int) $video->views),
    ]);
}
fclose($handle);

$beforeCount = $videos->count();
$beforeNonZero = $videos->filter(
    static fn (Video $video): bool => (int) $video->views !== 0
)->count();
$beforeSum = $videos->sum(
    static fn (Video $video): int => (int) $video->views
);

$updated = DB::transaction(
    static fn (): int => Video::query()
        ->where('video_source', 'eporner')
        ->where('views', '!=', 0)
        ->update(['views' => 0])
);

$afterCount = Video::query()
    ->where('video_source', 'eporner')
    ->count();
$afterNonZero = Video::query()
    ->where('video_source', 'eporner')
    ->where('views', '!=', 0)
    ->count();
$afterSum = (int) Video::query()
    ->where('video_source', 'eporner')
    ->sum('views');

if ($afterCount !== $beforeCount) {
    fail('Eporner video count changed unexpectedly during view reset.');
}

if ($afterNonZero !== 0 || $afterSum !== 0) {
    fail('Eporner view reset verification failed.');
}

echo 'EPORNER_VIDEOS=' . $beforeCount . PHP_EOL;
echo 'EPORNER_NONZERO_VIEWS_BEFORE=' . $beforeNonZero . PHP_EOL;
echo 'EPORNER_VIEW_SUM_BEFORE=' . $beforeSum . PHP_EOL;
echo 'EPORNER_VIEW_ROWS_UPDATED=' . $updated . PHP_EOL;
echo 'EPORNER_NONZERO_VIEWS_AFTER=' . $afterNonZero . PHP_EOL;
echo 'EPORNER_VIEW_SUM_AFTER=' . $afterSum . PHP_EOL;
echo 'EPORNER_VIEW_BACKUP=' . $backupPath . PHP_EOL;
echo 'EPORNER_VIEW_RESET=PASS' . PHP_EOL;
