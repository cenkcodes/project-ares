#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Filament\Imports\VideoImporter;
use App\Models\Category;
use App\Models\Video;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

const EXPECTED_POLICY_VERSION = '2026-08-29.3';
const DEFAULT_USER_ID = 1;
const DEFAULT_DELAY = 0.30;
const VIDEO_SOURCE = 'porntrex';

const VIDEO_HEADERS = [
    'title',
    'slug',
    'description',
    'embed_url',
    'video_source',
    'thumbnail',
    'duration',
    'category',
    'views',
    'is_hd',
    'is_4k',
    'is_featured',
    'is_premium',
    'is_active',
];

const SOURCE_TERM_HEADERS = [
    'video_slug',
    'video_source',
    'term_type',
    'term',
    'normalized_term',
];

function line(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function kv(
    string $key,
    string|int|float $value
): void {
    line($key . '=' . $value);
}

function abortRun(
    string $message,
    int $code = 1
): never {
    fwrite(
        STDERR,
        'ERROR=' . $message . PHP_EOL
    );

    exit($code);
}

function usage(int $code = 2): never
{
    line('Usage:');
    line(
        '  php porntrex-batch.php '
        . '<category> [minimum-count] '
        . '[--prepare-only|--import]'
    );

    line('');
    line('Examples:');
    line(
        '  php porntrex-batch.php '
        . 'big-ass 100 --prepare-only'
    );
    line(
        '  php porntrex-batch.php '
        . 'big-ass 100 --import'
    );

    line('');
    line('Options:');
    line(
        '  --backend=/path/to/current-site/backend'
    );
    line(
        '  --ops=/path/to/current-site/ops/video-import'
    );
    line('  --user-id=1');
    line('  --delay=0.30');
    line(
        '  --target=150 '
        . '(candidate ceiling)'
    );
    line('  --max-pages=40');
    line(
        '  --start-page=0 '
        . '(0 = PornTrex natural first page)'
    );
    line(
        '  --prepared-video='
        . '/path/to/prepared-videos.csv'
    );
    line(
        '  --prepared-terms='
        . '/path/to/prepared-source-terms.csv'
    );

    exit($code);
}

function parseArguments(array $argv): array
{
    $positionals = [];

    $options = [
        'mode' => 'prepare-only',
        'backend' => null,
        'ops' => null,
        'user-id' => DEFAULT_USER_ID,
        'delay' => DEFAULT_DELAY,
        'target' => null,
        'max-pages' => null,
        'start-page' => 0,
        'prepared-video' => null,
        'prepared-terms' => null,
    ];

    foreach (
        array_slice(
            $argv,
            1
        ) as $argument
    ) {
        if (
            $argument === '--help'
            || $argument === '-h'
        ) {
            usage(0);
        }

        if ($argument === '--import') {
            $options['mode'] = 'import';

            continue;
        }

        if ($argument === '--prepare-only') {
            $options['mode'] = 'prepare-only';

            continue;
        }

        if (str_starts_with(
            $argument,
            '--'
        )) {
            $parts = explode(
                '=',
                substr(
                    $argument,
                    2
                ),
                2
            );

            if (
                count($parts) !== 2
                || $parts[1] === ''
            ) {
                abortRun(
                    'Invalid option: '
                    . $argument,
                    2
                );
            }

            [$name, $value] = $parts;

            if (
                ! array_key_exists(
                    $name,
                    $options
                )
            ) {
                abortRun(
                    'Unknown option: --'
                    . $name,
                    2
                );
            }

            $options[$name] = $value;

            continue;
        }

        $positionals[] = $argument;
    }

    if ($positionals === []) {
        usage();
    }

    if (count($positionals) > 2) {
        abortRun(
            'Too many positional arguments.',
            2
        );
    }

    $category = strtolower(
        trim(
            (string) (
                $positionals[0]
                ?? ''
            )
        )
    );

    if (
        preg_match(
            '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            $category
        ) !== 1
    ) {
        abortRun(
            'Category must be a canonical '
            . 'lowercase slug.',
            2
        );
    }

    $count = (int) (
        $positionals[1]
        ?? 500
    );

    if (
        $count < 1
        || $count > 5000
    ) {
        abortRun(
            'Count must be between '
            . '1 and 5000.',
            2
        );
    }

    $userId = (int) $options['user-id'];
    $delay = (float) $options['delay'];

    if ($userId < 1) {
        abortRun(
            'user-id must be >= 1.',
            2
        );
    }

    if ($delay < 0) {
        abortRun(
            'delay must be >= 0.',
            2
        );
    }

    $target = (
        $options['target'] !== null
            ? (int) $options['target']
            : $count
                + max(
                    50,
                    (int) ceil(
                        $count * 0.30
                    )
                )
    );

    if ($target < $count) {
        abortRun(
            'target cannot be lower '
            . 'than minimum-count.',
            2
        );
    }

    if ($target > 5000) {
        abortRun(
            'target cannot exceed 5000.',
            2
        );
    }

    $maxPages = (
        $options['max-pages'] !== null
            ? (int) $options['max-pages']
            : max(
                10,
                (int) ceil(
                    $target / 120
                ) + 5
            )
    );

    if ($maxPages < 1) {
        abortRun(
            'max-pages must be >= 1.',
            2
        );
    }

    $startPage = (int) (
        $options['start-page']
    );

    if ($startPage < 0) {
        abortRun(
            'start-page must be >= 0.',
            2
        );
    }

    $preparedVideo = (
        $options['prepared-video']
        !== null
            ? trim(
                (string)
                $options['prepared-video']
            )
            : null
    );

    $preparedTerms = (
        $options['prepared-terms']
        !== null
            ? trim(
                (string)
                $options['prepared-terms']
            )
            : null
    );

    if (
        ($preparedVideo === null)
        !==
        ($preparedTerms === null)
    ) {
        abortRun(
            '--prepared-video and '
            . '--prepared-terms must '
            . 'be supplied together.',
            2
        );
    }

    $defaultOps = __DIR__;

    $defaultBackend = dirname(
        __DIR__,
        2
    ) . '/backend';

    $backend = (
        $options['backend'] !== null
            ? rtrim(
                (string)
                $options['backend'],
                '/'
            )
            : $defaultBackend
    );

    $ops = (
        $options['ops'] !== null
            ? rtrim(
                (string)
                $options['ops'],
                '/'
            )
            : $defaultOps
    );

    return [
        'category' => $category,
        'count' => $count,
        'mode' => $options['mode'],
        'backend' => $backend,
        'ops' => $ops,
        'userId' => $userId,
        'delay' => $delay,
        'target' => $target,
        'maxPages' => $maxPages,
        'startPage' => $startPage,
        'preparedVideo' => $preparedVideo,
        'preparedTerms' => $preparedTerms,
    ];
}

function removeDirectory(
    string $directory
): void {
    if (! is_dir($directory)) {
        return;
    }

    $items = scandir($directory);

    if ($items === false) {
        abortRun(
            'Unable to read directory ['
            . $directory
            . '].'
        );
    }

    foreach ($items as $item) {
        if (
            $item === '.'
            || $item === '..'
        ) {
            continue;
        }

        $path = $directory
            . DIRECTORY_SEPARATOR
            . $item;

        if (
            is_dir($path)
            && ! is_link($path)
        ) {
            removeDirectory($path);

            continue;
        }

        if (! unlink($path)) {
            abortRun(
                'Unable to delete ['
                . $path
                . '].'
            );
        }
    }

    if (! rmdir($directory)) {
        abortRun(
            'Unable to delete directory ['
            . $directory
            . '].'
        );
    }
}

function ensureFile(
    string $path
): void {
    if (! is_file($path)) {
        abortRun(
            'Required file missing: '
            . $path
        );
    }
}

function resolvePreparedFile(
    string $path,
    string $batchesRoot
): string {
    $resolved = realpath($path);

    if (
        $resolved === false
        || ! is_file($resolved)
    ) {
        abortRun(
            'Prepared file missing: '
            . $path
        );
    }

    if (
        ! str_starts_with(
            $resolved,
            $batchesRoot . '/'
        )
    ) {
        abortRun(
            'Prepared files must be '
            . 'inside the batches root ['
            . $batchesRoot
            . ']. Got ['
            . $resolved
            . '].'
        );
    }

    return $resolved;
}

function runLogged(
    array $command,
    string $logFile
): string {
    $commandString = implode(
        ' ',
        array_map(
            static fn (
                string $part
            ): string =>
                escapeshellarg($part),
            $command
        )
    );

    $logQuoted = escapeshellarg(
        $logFile
    );

    $bashScript =
        'set -o pipefail; '
        . $commandString
        . ' 2>&1 | tee '
        . $logQuoted;

    $shellCommand =
        'bash -lc '
        . escapeshellarg(
            $bashScript
        );

    passthru(
        $shellCommand,
        $status
    );

    if ($status !== 0) {
        abortRun(
            'Command failed with exit code '
            . $status
            . ': '
            . implode(
                ' ',
                $command
            )
        );
    }

    $content = file_get_contents(
        $logFile
    );

    if ($content === false) {
        abortRun(
            'Unable to read log file ['
            . $logFile
            . '].'
        );
    }

    return $content;
}

function runLoggedOrThrow(
    array $command,
    string $logFile
): string {
    $commandString = implode(
        ' ',
        array_map(
            static fn (
                string $part
            ): string =>
                escapeshellarg($part),
            $command
        )
    );

    $logQuoted = escapeshellarg(
        $logFile
    );

    $bashScript =
        'set -o pipefail; '
        . $commandString
        . ' 2>&1 | tee '
        . $logQuoted;

    $shellCommand =
        'bash -lc '
        . escapeshellarg(
            $bashScript
        );

    passthru(
        $shellCommand,
        $status
    );

    if ($status !== 0) {
        throw new RuntimeException(
            'Command failed with exit code '
            . $status
            . ': '
            . implode(
                ' ',
                $command
            )
        );
    }

    $content = file_get_contents(
        $logFile
    );

    if ($content === false) {
        throw new RuntimeException(
            'Unable to read log file ['
            . $logFile
            . '].'
        );
    }

    return $content;
}

function requireLogValue(
    string $log,
    string $key,
    ?string $expected = null
): string {
    if (
        ! preg_match(
            '/^'
            . preg_quote(
                $key,
                '/'
            )
            . '=(.*)$/m',
            $log,
            $match
        )
    ) {
        abortRun(
            'Required log value missing: '
            . $key
        );
    }

    $value = trim(
        $match[1]
    );

    if (
        $expected !== null
        && $value !== $expected
    ) {
        abortRun(
            $key
            . ' mismatch. Expected ['
            . $expected
            . '] got ['
            . $value
            . '].'
        );
    }

    return $value;
}

function requireLogValueOrThrow(
    string $log,
    string $key,
    ?string $expected = null
): string {
    if (
        ! preg_match(
            '/^'
            . preg_quote(
                $key,
                '/'
            )
            . '=(.*)$/m',
            $log,
            $match
        )
    ) {
        throw new RuntimeException(
            'Required log value missing: '
            . $key
        );
    }

    $value = trim(
        $match[1]
    );

    if (
        $expected !== null
        && $value !== $expected
    ) {
        throw new RuntimeException(
            $key
            . ' mismatch. Expected ['
            . $expected
            . '] got ['
            . $value
            . '].'
        );
    }

    return $value;
}

function readCsv(
    string $path
): array {
    $handle = fopen(
        $path,
        'rb'
    );

    if ($handle === false) {
        abortRun(
            'Unable to open CSV ['
            . $path
            . '].'
        );
    }

    $headers = fgetcsv(
        $handle,
        null,
        ',',
        '"',
        '\\'
    );

    if ($headers === false) {
        fclose($handle);

        abortRun(
            'CSV has no header row ['
            . $path
            . '].'
        );
    }

    if (isset($headers[0])) {
        $headers[0] = preg_replace(
            '/^\xEF\xBB\xBF/',
            '',
            (string)
            $headers[0]
        );
    }

    $rows = [];
    $lineNumber = 1;

    while (
        (
            $values = fgetcsv(
                $handle,
                null,
                ',',
                '"',
                '\\'
            )
        ) !== false
    ) {
        ++$lineNumber;

        if (
            $values === [null]
            || $values === []
        ) {
            continue;
        }

        if (
            count($values)
            !== count($headers)
        ) {
            fclose($handle);

            abortRun(
                'CSV column count mismatch '
                . 'at line '
                . $lineNumber
                . ' in ['
                . $path
                . '].'
            );
        }

        $row = array_combine(
            $headers,
            $values
        );

        if ($row === false) {
            fclose($handle);

            abortRun(
                'Unable to combine CSV row '
                . 'at line '
                . $lineNumber
                . '.'
            );
        }

        $rows[] = $row;
    }

    fclose($handle);

    return [
        $headers,
        $rows,
    ];
}

function exportExistingPorntrexIds(
    string $path
): array {
    $videos = Video::query()
        ->where(
            'video_source',
            VIDEO_SOURCE
        )
        ->get([
            'slug',
            'embed_url',
        ]);

    $ids = [];
    $unresolved = [];

    foreach ($videos as $video) {
        $id = null;

        $embedUrl = trim(
            (string)
            $video->embed_url
        );

        if (
            $embedUrl !== ''
            && preg_match(
                '~/embed/([0-9]+)~i',
                $embedUrl,
                $match
            )
        ) {
            $id = $match[1];
        }

        if ($id === null) {
            $slug = trim(
                (string)
                $video->slug
            );

            if (
                preg_match(
                    '/^porntrex-([0-9]+)'
                    . '(?:-|$)/i',
                    $slug,
                    $match
                )
            ) {
                $id = $match[1];
            }
        }

        if ($id === null) {
            $unresolved[] = (
                (string)
                $video->slug
            );

            continue;
        }

        $ids[$id] = true;
    }

    if ($unresolved !== []) {
        abortRun(
            'Existing PornTrex videos '
            . 'with unresolved provider IDs: '
            . implode(
                ',',
                array_slice(
                    $unresolved,
                    0,
                    20
                )
            )
        );
    }

    ksort($ids);

    $idList = array_keys($ids);

    $payload = (
        $idList === []
            ? ''
            : implode(
                PHP_EOL,
                $idList
            ) . PHP_EOL
    );

    if (
        file_put_contents(
            $path,
            $payload
        ) === false
    ) {
        abortRun(
            'Unable to write exclusion file ['
            . $path
            . '].'
        );
    }

    return [
        'liveCount' => $videos->count(),
        'ids' => $idList,
    ];
}

function validateBatch(
    string $category,
    int $minimumCount,
    int $expectedCount,
    string $videoCsv,
    string $termsCsv,
    array $excludedIds
): array {
    [
        $videoHeaders,
        $videos,
    ] = readCsv(
        $videoCsv
    );

    [
        $termHeaders,
        $terms,
    ] = readCsv(
        $termsCsv
    );

    if (
        $videoHeaders
        !== VIDEO_HEADERS
    ) {
        abortRun(
            'Video CSV headers mismatch. Got ['
            . implode(
                ',',
                $videoHeaders
            )
            . '].'
        );
    }

    if (
        $termHeaders
        !== SOURCE_TERM_HEADERS
    ) {
        abortRun(
            'Source-term CSV headers mismatch. '
            . 'Got ['
            . implode(
                ',',
                $termHeaders
            )
            . '].'
        );
    }

    if (
        count($videos)
        !== $expectedCount
    ) {
        abortRun(
            'Expected '
            . $expectedCount
            . ' video rows from builder, got '
            . count($videos)
            . '.'
        );
    }

    if (
        count($videos)
        < $minimumCount
    ) {
        abortRun(
            'Accepted video rows below minimum. '
            . 'Minimum '
            . $minimumCount
            . ', got '
            . count($videos)
            . '.'
        );
    }

    if ($terms === []) {
        abortRun(
            'Source-term CSV is empty.'
        );
    }

    $excludedLookup = array_fill_keys(
        $excludedIds,
        true
    );

    $slugs = [];
    $overlapIds = [];

    foreach (
        $videos
        as $index => $row
    ) {
        $rowNumber = $index + 2;

        $slug = trim(
            (string) (
                $row['slug']
                ?? ''
            )
        );

        if ($slug === '') {
            abortRun(
                'Blank slug at video CSV row '
                . $rowNumber
                . '.'
            );
        }

        if (isset($slugs[$slug])) {
            abortRun(
                'Duplicate video slug ['
                . $slug
                . '].'
            );
        }

        if (
            preg_match(
                '/^porntrex-[0-9]+$/',
                $slug
            ) !== 1
        ) {
            abortRun(
                'Invalid PornTrex slug ['
                . $slug
                . '].'
            );
        }

        $slugs[$slug] = true;

        $videoSource = trim(
            (string) (
                $row['video_source']
                ?? ''
            )
        );

        if (
            $videoSource
            !== VIDEO_SOURCE
        ) {
            abortRun(
                'Invalid video_source for slug ['
                . $slug
                . '].'
            );
        }

        $rowCategory = trim(
            (string) (
                $row['category']
                ?? ''
            )
        );

        if (
            $rowCategory
            !== $category
        ) {
            abortRun(
                'Invalid category for slug ['
                . $slug
                . '].'
            );
        }

        $embedUrl = trim(
            (string) (
                $row['embed_url']
                ?? ''
            )
        );

        if (
            ! preg_match(
                '~^https://www\.porntrex\.com/'
                . 'embed/([0-9]+)$~i',
                $embedUrl,
                $match
            )
        ) {
            abortRun(
                'Invalid embed_url for slug ['
                . $slug
                . '].'
            );
        }

        $id = $match[1];

        if (
            isset(
                $excludedLookup[$id]
            )
        ) {
            $overlapIds[$id] = true;
        }
    }

    if ($overlapIds !== []) {
        abortRun(
            'Existing PornTrex overlap detected: '
            . implode(
                ',',
                array_slice(
                    array_keys(
                        $overlapIds
                    ),
                    0,
                    20
                )
            )
        );
    }

    $termVideoSlugs = [];
    $termKeys = [];

    foreach (
        $terms
        as $index => $row
    ) {
        $rowNumber = $index + 2;

        $videoSlug = trim(
            (string) (
                $row['video_slug']
                ?? ''
            )
        );

        $videoSource = trim(
            (string) (
                $row['video_source']
                ?? ''
            )
        );

        $termType = trim(
            (string) (
                $row['term_type']
                ?? ''
            )
        );

        $term = trim(
            (string) (
                $row['term']
                ?? ''
            )
        );

        $normalizedTerm = trim(
            (string) (
                $row['normalized_term']
                ?? ''
            )
        );

        if (
            ! isset(
                $slugs[$videoSlug]
            )
        ) {
            abortRun(
                'Source-term row '
                . $rowNumber
                . ' references slug '
                . 'outside batch ['
                . $videoSlug
                . '].'
            );
        }

        if (
            $videoSource
            !== VIDEO_SOURCE
        ) {
            abortRun(
                'Source-term row '
                . $rowNumber
                . ' has invalid source.'
            );
        }

        if (
            $termType === ''
            || $term === ''
            || $normalizedTerm === ''
        ) {
            abortRun(
                'Source-term row '
                . $rowNumber
                . ' has blank key fields.'
            );
        }

        $termVideoSlugs[
            $videoSlug
        ] = true;

        $key = implode(
            '|',
            [
                $videoSlug,
                $videoSource,
                $termType,
                $normalizedTerm,
            ]
        );

        if (
            isset(
                $termKeys[$key]
            )
        ) {
            abortRun(
                'Duplicate source-term '
                . 'input key ['
                . $key
                . '].'
            );
        }

        $termKeys[$key] = true;
    }

    $missingSourceTerms =
        array_diff_key(
            $slugs,
            $termVideoSlugs
        );

    if (
        $missingSourceTerms !== []
    ) {
        abortRun(
            'Videos without source terms: '
            . implode(
                ',',
                array_slice(
                    array_keys(
                        $missingSourceTerms
                    ),
                    0,
                    20
                )
            )
        );
    }

    kv(
        'VIDEO_ROWS',
        count($videos)
    );

    kv(
        'UNIQUE_VIDEO_SLUGS',
        count($slugs)
    );

    kv(
        'SOURCE_TERM_ROWS',
        count($terms)
    );

    kv(
        'SOURCE_TERM_VIDEO_COUNT',
        count($termVideoSlugs)
    );

    kv(
        'EXISTING_VIDEO_OVERLAP',
        0
    );

    kv(
        'VIDEOS_WITHOUT_SOURCE_TERMS',
        0
    );

    line(
        'BATCH_STRUCTURE_VALIDATION=PASS'
    );

    return [
        'videos' => $videos,
        'terms' => $terms,
        'slugs' => array_keys(
            $slugs
        ),
        'termCount' => count(
            $terms
        ),
    ];
}

function authenticateImportUser(
    int $userId
): object {
    $modelClass = (string) config(
        'auth.providers.users.model',
        App\Models\User::class
    );

    if (
        ! class_exists(
            $modelClass
        )
    ) {
        abortRun(
            'Configured user model '
            . 'does not exist ['
            . $modelClass
            . '].'
        );
    }

    $user = $modelClass::query()
        ->find($userId);

    if ($user === null) {
        abortRun(
            'Import user not found '
            . 'for user_id='
            . $userId
            . '.'
        );
    }

    Auth::login($user);

    return $user;
}

function makeImportRecord(
    string $fileName,
    string $filePath,
    int $totalRows,
    int $userId
): Import {
    $import = new Import();

    $import->file_name = $fileName;
    $import->file_path = $filePath;
    $import->importer =
        VideoImporter::class;
    $import->processed_rows = 0;
    $import->total_rows = $totalRows;
    $import->successful_rows = 0;
    $import->user_id = $userId;

    $import->save();

    return $import;
}

function importerSmokeTest(
    array $firstRow,
    array $headers,
    string $videoCsv,
    int $userId
): void {
    line('');
    line(
        '===== VIDEOIMPORTER '
        . 'TRANSACTIONAL SMOKE ====='
    );

    $slug = (string) $firstRow['slug'];

    if (
        Video::query()
            ->where(
                'slug',
                $slug
            )
            ->exists()
    ) {
        abortRun(
            'Smoke-test slug already exists ['
            . $slug
            . '].'
        );
    }

    DB::beginTransaction();

    try {
        $import = makeImportRecord(
            'SMOKE-'
            . basename(
                $videoCsv
            ),
            $videoCsv,
            1,
            $userId
        );

        $columnMap =
            array_combine(
                $headers,
                $headers
            );

        if ($columnMap === false) {
            throw new RuntimeException(
                'Unable to build '
                . 'identity column map.'
            );
        }

        $importer =
            new VideoImporter(
                $import,
                $columnMap,
                []
            );

        $importer(
            $firstRow
        );

        if (
            ! Video::query()
                ->where(
                    'slug',
                    $slug
                )
                ->exists()
        ) {
            throw new RuntimeException(
                'VideoImporter did not create '
                . 'smoke-test slug ['
                . $slug
                . '].'
            );
        }

        DB::rollBack();
    } catch (Throwable $exception) {
        if (
            DB::transactionLevel()
            > 0
        ) {
            DB::rollBack();
        }

        abortRun(
            'VideoImporter smoke test failed: '
            . $exception::class
            . ': '
            . $exception->getMessage()
        );
    }

    if (
        Video::query()
            ->where(
                'slug',
                $slug
            )
            ->exists()
    ) {
        abortRun(
            'Smoke-test rollback failed '
            . 'for slug ['
            . $slug
            . '].'
        );
    }

    line(
        'VIDEOIMPORTER_SMOKE=PASS'
    );
}

function importVideosWithExistingImporter(
    array $videos,
    array $headers,
    string $videoCsv,
    string $category,
    int $userId
): Import {
    line('');
    line(
        '===== REAL VIDEO IMPORT '
        . 'VIA EXISTING VIDEOIMPORTER ====='
    );

    $import = makeImportRecord(
        basename(
            $videoCsv
        ),
        $videoCsv,
        count($videos),
        $userId
    );

    $columnMap =
        array_combine(
            $headers,
            $headers
        );

    if ($columnMap === false) {
        abortRun(
            'Unable to build '
            . 'identity column map.'
        );
    }

    $processed = 0;
    $slugs = [];

    DB::beginTransaction();

    try {
        foreach (
            $videos
            as $row
        ) {
            ++$processed;

            $slug = trim(
                (string)
                $row['slug']
            );

            $slugs[] = $slug;

            $importer =
                new VideoImporter(
                    $import,
                    $columnMap,
                    []
                );

            $importer($row);

            if (
                ($processed % 100)
                === 0
                || $processed
                === count($videos)
            ) {
                kv(
                    'VIDEO_IMPORT_PROGRESS',
                    $processed
                    . '/'
                    . count($videos)
                );
            }
        }

        $persistedCount =
            Video::query()
                ->whereIn(
                    'slug',
                    $slugs
                )
                ->count();

        if (
            $persistedCount
            !== count($videos)
        ) {
            throw new RuntimeException(
                'Persisted batch count mismatch. '
                . 'Expected '
                . count($videos)
                . ', got '
                . $persistedCount
                . '.'
            );
        }

        $categoryCount =
            Video::query()
                ->whereIn(
                    'slug',
                    $slugs
                )
                ->whereHas(
                    'category',
                    static fn ($query) =>
                        $query->where(
                            'slug',
                            $category
                        )
                )
                ->count();

        if (
            $categoryCount
            !== count($videos)
        ) {
            throw new RuntimeException(
                'Imported category count mismatch. '
                . 'Expected '
                . count($videos)
                . ', got '
                . $categoryCount
                . '.'
            );
        }

        DB::commit();
    } catch (Throwable $exception) {
        if (
            DB::transactionLevel()
            > 0
        ) {
            DB::rollBack();
        }

        $import->processed_rows =
            $processed;

        $import->successful_rows = 0;
        $import->completed_at = now();
        $import->save();

        abortRun(
            'Video import rolled back '
            . 'at row '
            . $processed
            . ': '
            . $exception::class
            . ': '
            . $exception->getMessage()
        );
    }

    $import->processed_rows =
        count($videos);

    $import->successful_rows =
        count($videos);

    $import->save();

    kv(
        'VIDEO_IMPORT_ROWS',
        count($videos)
    );

    line(
        'VIDEO_IMPORT=PASS'
    );

    return $import;
}

function compensateBatch(
    array $slugs,
    ?Import $import,
    string $reason
): never {
    line('');
    line(
        '===== COMPENSATING ROLLBACK ====='
    );

    line(
        'ROLLBACK_REASON='
        . $reason
    );

    DB::transaction(
        function () use ($slugs): void {
            $videoIds =
                Video::query()
                    ->whereIn(
                        'slug',
                        $slugs
                    )
                    ->pluck('id');

            if (
                $videoIds->isNotEmpty()
            ) {
                DB::table(
                    'video_source_terms'
                )
                    ->whereIn(
                        'video_id',
                        $videoIds->all()
                    )
                    ->delete();
            }

            Video::query()
                ->whereIn(
                    'slug',
                    $slugs
                )
                ->delete();
        }
    );

    if (
        $import !== null
        && $import->exists
    ) {
        $import->successful_rows = 0;
        $import->completed_at = now();

        $import->save();
    }

    line(
        'COMPENSATING_ROLLBACK=PASS'
    );

    abortRun($reason);
}

function batchSourceTermStats(
    array $slugs
): array {
    $videoIds =
        Video::query()
            ->whereIn(
                'slug',
                $slugs
            )
            ->pluck('id');

    if ($videoIds->isEmpty()) {
        return [
            'rows' => 0,
            'videoCount' => 0,
        ];
    }

    return [
        'rows' =>
            DB::table(
                'video_source_terms'
            )
                ->whereIn(
                    'video_id',
                    $videoIds->all()
                )
                ->count(),

        'videoCount' =>
            DB::table(
                'video_source_terms'
            )
                ->whereIn(
                    'video_id',
                    $videoIds->all()
                )
                ->distinct()
                ->count(
                    'video_id'
                ),
    ];
}

$config = parseArguments(
    $argv
);

$category =
    $config['category'];

$count =
    $config['count'];

$mode =
    $config['mode'];

$backend =
    $config['backend'];

$ops =
    $config['ops'];

$userId =
    $config['userId'];

$delay =
    $config['delay'];

$target =
    $config['target'];

$maxPages =
    $config['maxPages'];

$startPage =
    $config['startPage'];

$preparedVideo =
    $config['preparedVideo'];

$preparedTerms =
    $config['preparedTerms'];

$preparedMode =
    $preparedVideo !== null;

$autoload =
    $backend
    . '/vendor/autoload.php';

$bootstrap =
    $backend
    . '/bootstrap/app.php';

$collector =
    $ops
    . '/porntrex-url-collector.py';

$builder =
    $ops
    . '/porntrex-csv-builder.py';

$sourceTermImporter =
    $ops
    . '/import-video-source-terms.php';

$policyFile =
    $ops
    . '/video_import_policy.py';

$requiredFiles = [
    $autoload,
    $bootstrap,
    $sourceTermImporter,
];

if (! $preparedMode) {
    $requiredFiles[] =
        $collector;

    $requiredFiles[] =
        $builder;

    $requiredFiles[] =
        $policyFile;
}

foreach (
    $requiredFiles
    as $requiredFile
) {
    ensureFile(
        $requiredFile
    );
}

require $autoload;

$app = require $bootstrap;

$app->make(
    Kernel::class
)->bootstrap();

$activeCategory =
    Category::query()
        ->where(
            'slug',
            $category
        )
        ->where(
            'is_active',
            true
        )
        ->exists();

if (! $activeCategory) {
    abortRun(
        'Category ['
        . $category
        . '] is not an active '
        . 'canonical site category.',
        2
    );
}

$lockPath =
    $ops
    . '/.porntrex-batch.lock';

$lockHandle = fopen(
    $lockPath,
    'c+'
);

if ($lockHandle === false) {
    abortRun(
        'Unable to open batch lock ['
        . $lockPath
        . '].'
    );
}

if (
    ! flock(
        $lockHandle,
        LOCK_EX | LOCK_NB
    )
) {
    abortRun(
        'Another PornTrex batch '
        . 'process is already running.'
    );
}

register_shutdown_function(
    static function () use (
        $lockHandle
    ): void {
        flock(
            $lockHandle,
            LOCK_UN
        );

        fclose(
            $lockHandle
        );
    }
);

line(
    '===== PORNTREX BATCH ====='
);

kv(
    'VIDEO_SOURCE',
    VIDEO_SOURCE
);

kv(
    'CATEGORY',
    $category
);

kv(
    'MIN_ACCEPTED',
    $count
);

kv(
    'MODE',
    $mode
);

kv(
    'INPUT_MODE',
    $preparedMode
        ? 'PREPARED_REUSE'
        : 'COLLECT_BUILD'
);

kv(
    'IMPORT_STRATEGY',
    $preparedMode
        ? 'PREPARED_MINIMUM_PLUS_ALL_PASS'
        : 'MINIMUM_PLUS_ALL_PASS'
);

if (! $preparedMode) {
    kv(
        'TARGET_CANDIDATES',
        $target
    );

    kv(
        'MAX_PAGES',
        $maxPages
    );

    kv(
        'START_PAGE',
        $startPage
    );

    kv(
        'EXPECTED_POLICY_VERSION',
        EXPECTED_POLICY_VERSION
    );
}

$batchesDirectory =
    $ops . '/batches';

if (
    ! is_dir(
        $batchesDirectory
    )
) {
    if (
        ! mkdir(
            $batchesDirectory,
            0775,
            true
        )
        && ! is_dir(
            $batchesDirectory
        )
    ) {
        abortRun(
            'Unable to create '
            . 'batches directory.'
        );
    }
}

$batchesRoot =
    realpath(
        $batchesDirectory
    );

if ($batchesRoot === false) {
    abortRun(
        'Unable to resolve '
        . 'batches directory.'
    );
}

$batchName = (
    $preparedMode
        ? 'porntrex-'
            . $category
            . '-prepared-import-v8'
        : 'porntrex-'
            . $category
            . '-'
            . $count
            . '-v8'
);

$batch =
    $batchesRoot
    . '/'
    . $batchName;

if (
    ! str_starts_with(
        $batch,
        $batchesRoot . '/'
    )
) {
    abortRun(
        'Unsafe batch path.'
    );
}

removeDirectory(
    $batch
);

if (
    ! mkdir(
        $batch,
        0775,
        true
    )
    && ! is_dir($batch)
) {
    abortRun(
        'Unable to create batch directory ['
        . $batch
        . '].'
    );
}

$excludeFile =
    $batch
    . '/existing-porntrex-ids.txt';

$candidateFile =
    $batch
    . '/'
    . $category
    . '-candidate-urls.txt';

$collectorLog =
    $batch
    . '/'
    . $category
    . '-collector.log';

$builderLog =
    $batch
    . '/'
    . $category
    . '-builder.log';

$sourceDryLog =
    $batch
    . '/'
    . $category
    . '-source-terms-dry-run.log';

$sourceImportLog =
    $batch
    . '/'
    . $category
    . '-source-terms-import.log';

if ($preparedMode) {
    $videoCsv =
        resolvePreparedFile(
            (string)
            $preparedVideo,
            $batchesRoot
        );

    $termsCsv =
        resolvePreparedFile(
            (string)
            $preparedTerms,
            $batchesRoot
        );

    $rejectCsv = null;

    [
        $preparedHeaders,
        $preparedVideos,
    ] = readCsv(
        $videoCsv
    );

    if (
        $preparedHeaders
        !== VIDEO_HEADERS
    ) {
        abortRun(
            'Prepared video CSV '
            . 'headers mismatch.'
        );
    }

    $acceptedCount =
        count(
            $preparedVideos
        );

    if (
        $acceptedCount
        < $count
    ) {
        abortRun(
            'Prepared accepted rows '
            . 'below minimum. Minimum ['
            . $count
            . '] got ['
            . $acceptedCount
            . '].'
        );
    }

    if (
        $acceptedCount
        > $target
    ) {
        abortRun(
            'Prepared accepted rows '
            . 'exceed target. Target ['
            . $target
            . '] got ['
            . $acceptedCount
            . '].'
        );
    }
} else {
    $videoCsv =
        $batch
        . '/xurvexa-porntrex-'
        . $category
        . '-'
        . $count
        . '.csv';

    $rejectCsv =
        $batch
        . '/xurvexa-porntrex-'
        . $category
        . '-'
        . $count
        . '-rejected.csv';

    $termsCsv =
        $batch
        . '/xurvexa-porntrex-'
        . $category
        . '-'
        . $count
        . '-source-terms.csv';

    line('');
    line(
        '===== LIVE PORNTREX '
        . 'EXCLUSION EXPORT ====='
    );

    $exclusion =
        exportExistingPorntrexIds(
            $excludeFile
        );

    $liveCount =
        $exclusion['liveCount'];

    $excludedIds =
        $exclusion['ids'];

    kv(
        'LIVE_PORNTREX_VIDEOS',
        $liveCount
    );

    kv(
        'EXCLUDED_PROVIDER_IDS',
        count(
            $excludedIds
        )
    );

    if (
        $liveCount
        !== count(
            $excludedIds
        )
    ) {
        abortRun(
            'Existing PornTrex video '
            . 'count does not equal '
            . 'resolved provider ID count.'
        );
    }

    line(
        'LIVE_EXCLUSION_EXPORT=PASS'
    );

    line('');
    line(
        '===== COLLECT CANDIDATES ====='
    );

    $collectorOutput =
        runLogged(
            [
                'python3',
                $collector,
                '--query',
                $category,
                '--target',
                (string) $target,
                '--start-page',
                (string) $startPage,
                '--max-pages',
                (string) $maxPages,
                '--delay',
                number_format(
                    $delay,
                    2,
                    '.',
                    ''
                ),
                '--exclude-ids',
                $excludeFile,
                '--output',
                $candidateFile,
            ],
            $collectorLog
        );

    requireLogValue(
        $collectorOutput,
        'POLICY_VERSION',
        EXPECTED_POLICY_VERSION
    );

    requireLogValue(
        $collectorOutput,
        'EXCLUDED_IDS',
        (string)
        count(
            $excludedIds
        )
    );

    requireLogValue(
        $collectorOutput,
        'COLLECT_STATUS',
        'PASS'
    );

    line(
        'COLLECTOR_VALIDATION=PASS'
    );

    line('');
    line(
        '===== BUILD CANONICAL CSV ====='
    );

    $builderOutput =
        runLogged(
            [
                'python3',
                $builder,
                '--input',
                $candidateFile,
                '--category',
                $category,
                '--output',
                $videoCsv,
                '--rejects',
                $rejectCsv,
                '--source-terms',
                $termsCsv,
                '--max-accepted',
                (string) $target,
                '--delay',
                number_format(
                    $delay,
                    2,
                    '.',
                    ''
                ),
            ],
            $builderLog
        );

    requireLogValue(
        $builderOutput,
        'POLICY_VERSION',
        EXPECTED_POLICY_VERSION
    );

    requireLogValue(
        $builderOutput,
        'VIDEO_SOURCE',
        VIDEO_SOURCE
    );

    requireLogValue(
        $builderOutput,
        'BUILD_STATUS',
        'PASS'
    );

    $acceptedCount = (int)
        requireLogValue(
            $builderOutput,
            'ROWS_ACCEPTED'
        );

    if (
        $acceptedCount
        < $count
    ) {
        abortRun(
            'ROWS_ACCEPTED below minimum. '
            . 'Minimum ['
            . $count
            . '] got ['
            . $acceptedCount
            . '].'
        );
    }

    if (
        $acceptedCount
        > $target
    ) {
        abortRun(
            'ROWS_ACCEPTED exceeds target. '
            . 'Target ['
            . $target
            . '] got ['
            . $acceptedCount
            . '].'
        );
    }

    kv(
        'MIN_ACCEPTED',
        $count
    );

    kv(
        'ACTUAL_ACCEPTED',
        $acceptedCount
    );

    line(
        'BUILDER_VALIDATION=PASS'
    );

    ensureFile(
        $videoCsv
    );

    ensureFile(
        $termsCsv
    );

    ensureFile(
        $rejectCsv
    );
}

if ($preparedMode) {
    $exclusion =
        exportExistingPorntrexIds(
            $excludeFile
        );

    $liveCount =
        $exclusion['liveCount'];

    $excludedIds =
        $exclusion['ids'];

    kv(
        'LIVE_PORNTREX_VIDEOS',
        $liveCount
    );

    kv(
        'EXCLUDED_PROVIDER_IDS',
        count(
            $excludedIds
        )
    );
}

line('');
line(
    '===== BATCH STRUCTURE VALIDATION ====='
);

$batchData =
    validateBatch(
        $category,
        $count,
        $acceptedCount,
        $videoCsv,
        $termsCsv,
        $excludedIds
    );

line('');
line(
    '===== PREPARED FILES ====='
);

kv(
    'VIDEO_CSV',
    $videoCsv
);

kv(
    'SOURCE_TERMS_CSV',
    $termsCsv
);

kv(
    'REJECTS_CSV',
    $preparedMode
        ? 'NOT_USED_PREPARED_REUSE'
        : (string) $rejectCsv
);

if ($mode === 'prepare-only') {
    line('');
    line(
        '===== DATABASE UNCHANGED CHECK ====='
    );

    kv(
        'TOTAL_VIDEOS_NOW',
        Video::query()
            ->count()
    );

    kv(
        'PORNTREX_VIDEOS_NOW',
        Video::query()
            ->where(
                'video_source',
                VIDEO_SOURCE
            )
            ->count()
    );

    kv(
        'VIDEO_SOURCE_TERMS_NOW',
        DB::table(
            'video_source_terms'
        )
            ->count()
    );

    line('');
    line(
        'BATCH_PREPARATION=PASS'
    );

    line(
        '===== PORNTREX BATCH COMPLETE ====='
    );

    exit(0);
}

authenticateImportUser(
    $userId
);

$videos =
    $batchData['videos'];

$slugs =
    $batchData['slugs'];

$termCount =
    $batchData['termCount'];

$videoHeaders =
    VIDEO_HEADERS;

$importedCount =
    count($videos);

$beforeTotalVideos =
    Video::query()
        ->count();

$beforeProviderVideos =
    Video::query()
        ->where(
            'video_source',
            VIDEO_SOURCE
        )
        ->count();

$beforeCategoryVideos =
    Video::query()
        ->whereHas(
            'category',
            static fn ($query) =>
                $query->where(
                    'slug',
                    $category
                )
        )
        ->count();

$beforeSourceTerms =
    DB::table(
        'video_source_terms'
    )
        ->count();

importerSmokeTest(
    $videos[0],
    $videoHeaders,
    $videoCsv,
    $userId
);

$import =
    importVideosWithExistingImporter(
        $videos,
        $videoHeaders,
        $videoCsv,
        $category,
        $userId
    );

line('');
line(
    '===== SOURCE-TERM DRY RUN ====='
);

try {
    $sourceDryOutput =
        runLoggedOrThrow(
            [
                'php',
                $sourceTermImporter,
                '--input='
                . $termsCsv,
                '--backend='
                . $backend,
                '--expected-source='
                . VIDEO_SOURCE,
                '--dry-run',
            ],
            $sourceDryLog
        );

    requireLogValueOrThrow(
        $sourceDryOutput,
        'SOURCE_TERM_IMPORT',
        'PASS'
    );

    requireLogValueOrThrow(
        $sourceDryOutput,
        'MISSING_VIDEOS',
        '0'
    );

    requireLogValueOrThrow(
        $sourceDryOutput,
        'SOURCE_MISMATCHES',
        '0'
    );

    requireLogValueOrThrow(
        $sourceDryOutput,
        'DUPLICATE_INPUT_KEYS',
        '0'
    );

    requireLogValueOrThrow(
        $sourceDryOutput,
        'NORMALIZATION_MISMATCHES',
        '0'
    );

    requireLogValueOrThrow(
        $sourceDryOutput,
        'ROWS_UPSERTED',
        '0'
    );

    line(
        'SOURCE_TERM_DRY_RUN=PASS'
    );
} catch (Throwable $exception) {
    compensateBatch(
        $slugs,
        $import,
        'Source-term dry run failed: '
        . $exception->getMessage()
    );
}

line('');
line(
    '===== SOURCE-TERM REAL IMPORT ====='
);

try {
    $sourceImportOutput =
        runLoggedOrThrow(
            [
                'php',
                $sourceTermImporter,
                '--input='
                . $termsCsv,
                '--backend='
                . $backend,
                '--expected-source='
                . VIDEO_SOURCE,
            ],
            $sourceImportLog
        );

    requireLogValueOrThrow(
        $sourceImportOutput,
        'SOURCE_TERM_IMPORT',
        'PASS'
    );

    requireLogValueOrThrow(
        $sourceImportOutput,
        'MISSING_VIDEOS',
        '0'
    );

    requireLogValueOrThrow(
        $sourceImportOutput,
        'SOURCE_MISMATCHES',
        '0'
    );

    requireLogValueOrThrow(
        $sourceImportOutput,
        'DUPLICATE_INPUT_KEYS',
        '0'
    );

    requireLogValueOrThrow(
        $sourceImportOutput,
        'NORMALIZATION_MISMATCHES',
        '0'
    );

    requireLogValueOrThrow(
        $sourceImportOutput,
        'ROWS_UPSERTED',
        (string) $termCount
    );

    line(
        'SOURCE_TERM_REAL_IMPORT=PASS'
    );
} catch (Throwable $exception) {
    compensateBatch(
        $slugs,
        $import,
        'Source-term real import failed: '
        . $exception->getMessage()
    );
}

line('');
line(
    '===== FINAL DB INTEGRITY ====='
);

$afterTotalVideos =
    Video::query()
        ->count();

$afterProviderVideos =
    Video::query()
        ->where(
            'video_source',
            VIDEO_SOURCE
        )
        ->count();

$afterCategoryVideos =
    Video::query()
        ->whereHas(
            'category',
            static fn ($query) =>
                $query->where(
                    'slug',
                    $category
                )
        )
        ->count();

$afterSourceTerms =
    DB::table(
        'video_source_terms'
    )
        ->count();

$batchStats =
    batchSourceTermStats(
        $slugs
    );

$orphanSourceTerms =
    DB::table(
        'video_source_terms as vst'
    )
        ->leftJoin(
            'videos as v',
            'v.id',
            '=',
            'vst.video_id'
        )
        ->whereNull(
            'v.id'
        )
        ->count();

kv(
    'TOTAL_VIDEOS_BEFORE',
    $beforeTotalVideos
);

kv(
    'TOTAL_VIDEOS_AFTER',
    $afterTotalVideos
);

kv(
    'PORNTREX_VIDEOS_BEFORE',
    $beforeProviderVideos
);

kv(
    'PORNTREX_VIDEOS_AFTER',
    $afterProviderVideos
);

kv(
    'CATEGORY_VIDEOS_BEFORE',
    $beforeCategoryVideos
);

kv(
    'CATEGORY_VIDEOS_AFTER',
    $afterCategoryVideos
);

kv(
    'SOURCE_TERMS_BEFORE',
    $beforeSourceTerms
);

kv(
    'SOURCE_TERMS_AFTER',
    $afterSourceTerms
);

kv(
    'BATCH_SOURCE_TERM_ROWS',
    $batchStats['rows']
);

kv(
    'BATCH_SOURCE_TERM_VIDEO_COUNT',
    $batchStats['videoCount']
);

kv(
    'ORPHAN_SOURCE_TERMS',
    $orphanSourceTerms
);

$integrityFailure = null;

if (
    $afterTotalVideos
    !==
    $beforeTotalVideos
    + $importedCount
) {
    $integrityFailure =
        'Total video delta mismatch.';
} elseif (
    $afterProviderVideos
    !==
    $beforeProviderVideos
    + $importedCount
) {
    $integrityFailure =
        'PornTrex video delta mismatch.';
} elseif (
    $afterCategoryVideos
    !==
    $beforeCategoryVideos
    + $importedCount
) {
    $integrityFailure =
        'Category video delta mismatch.';
} elseif (
    $afterSourceTerms
    !==
    $beforeSourceTerms
    + $termCount
) {
    $integrityFailure =
        'Source-term delta mismatch.';
} elseif (
    $batchStats['rows']
    !== $termCount
) {
    $integrityFailure =
        'Batch source-term row '
        . 'count mismatch.';
} elseif (
    $batchStats['videoCount']
    !== $importedCount
) {
    $integrityFailure =
        'Batch source-term video '
        . 'coverage mismatch.';
} elseif (
    $orphanSourceTerms !== 0
) {
    $integrityFailure =
        'Orphan source terms detected.';
}

if ($integrityFailure !== null) {
    compensateBatch(
        $slugs,
        $import,
        $integrityFailure
    );
}

line(
    'FINAL_DB_INTEGRITY=PASS'
);

line(
    'AUTO_IMPORT=PASS'
);

line(
    '===== PORNTREX BATCH COMPLETE ====='
);