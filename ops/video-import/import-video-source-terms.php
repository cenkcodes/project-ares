<?php

declare(strict_types=1);

use App\Models\CategoryAlias;
use App\Models\Video;
use App\Services\VideoTaxonomyResolver;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;

function fail(string $message, int $code = 1): never
{
    fwrite(
        STDERR,
        $message . PHP_EOL
    );

    exit($code);
}

function printUsage(): void
{
    echo <<<'TEXT'
Usage:
  php import-video-source-terms.php --input=/path/to/source-terms.csv [options]

Options:
  --backend=/path/to/backend
      Laravel backend root.
      Default: ../../backend relative to this script.

  --expected-source=xvideos
      Optional source guard.

  --dry-run
      Validate and report only. Do not write database rows.

  --help
      Show this help.

Expected CSV headers:
  video_slug,video_source,term_type,term,normalized_term

TEXT;
}

$options = getopt(
    '',
    [
        'backend::',
        'input:',
        'expected-source::',
        'dry-run',
        'help',
    ]
);

if (isset($options['help'])) {
    printUsage();
    exit(0);
}

$scriptRoot = dirname(__DIR__, 2);

$backend = isset($options['backend'])
    ? rtrim(
        (string) $options['backend'],
        DIRECTORY_SEPARATOR
    )
    : $scriptRoot .
        DIRECTORY_SEPARATOR .
        'backend';

$input = isset($options['input'])
    ? (string) $options['input']
    : '';

$expectedSource = isset(
    $options['expected-source']
)
    ? strtolower(
        trim(
            (string) $options['expected-source']
        )
    )
    : null;

$dryRun = isset($options['dry-run']);

if ($input === '') {
    printUsage();
    fail('ERROR=--input is required.');
}

$autoload = $backend .
    DIRECTORY_SEPARATOR .
    'vendor' .
    DIRECTORY_SEPARATOR .
    'autoload.php';

$bootstrap = $backend .
    DIRECTORY_SEPARATOR .
    'bootstrap' .
    DIRECTORY_SEPARATOR .
    'app.php';

if (! is_file($autoload)) {
    fail(
        'ERROR=Laravel autoload not found: ' .
        $autoload
    );
}

if (! is_file($bootstrap)) {
    fail(
        'ERROR=Laravel bootstrap not found: ' .
        $bootstrap
    );
}

if (! is_file($input)) {
    fail(
        'ERROR=Input CSV not found: ' .
        $input
    );
}

require $autoload;

$app = require $bootstrap;

$kernel = $app->make(
    ConsoleKernel::class
);

$kernel->bootstrap();

$resolver = $app->make(
    VideoTaxonomyResolver::class
);

$handle = fopen(
    $input,
    'rb'
);

if ($handle === false) {
    fail(
        'ERROR=Could not open input CSV.'
    );
}

$header = fgetcsv(
    $handle,
    null,
    ',',
    '"',
    '\\'
);

if ($header === false) {
    fclose($handle);
    fail(
        'ERROR=Input CSV is empty.'
    );
}

if (isset($header[0])) {
    $header[0] = preg_replace(
        '/^\xEF\xBB\xBF/',
        '',
        (string) $header[0]
    ) ?? (string) $header[0];
}

$expectedHeaders = [
    'video_slug',
    'video_source',
    'term_type',
    'term',
    'normalized_term',
];

if ($header !== $expectedHeaders) {
    fclose($handle);

    fail(
        'ERROR=Unexpected CSV headers. GOT=' .
        implode(',', $header)
    );
}

$inputRows = 0;
$uniqueRows = [];
$duplicateInputKeys = [];
$normalizationMismatches = [];

while (
    ($row = fgetcsv(
        $handle,
        null,
        ',',
        '"',
        '\\'
    )) !== false
) {
    $inputRows++;

    if (count($row) !== count($header)) {
        fclose($handle);

        fail(
            'ERROR=Invalid column count at data row ' .
            $inputRows .
            '.'
        );
    }

    $data = array_combine(
        $header,
        $row
    );

    if ($data === false) {
        fclose($handle);

        fail(
            'ERROR=Could not combine CSV row ' .
            $inputRows .
            '.'
        );
    }

    $videoSlug = trim(
        (string) $data['video_slug']
    );

    $source = strtolower(
        trim(
            (string) $data['video_source']
        )
    );

    $termType = strtolower(
        trim(
            (string) $data['term_type']
        )
    );

    $term = trim(
        (string) $data['term']
    );

    $normalizedTerm = trim(
        (string) $data['normalized_term']
    );

    if (
        $videoSlug === '' ||
        $source === '' ||
        $termType === '' ||
        $term === '' ||
        $normalizedTerm === ''
    ) {
        fclose($handle);

        fail(
            'ERROR=Blank required value at data row ' .
            $inputRows .
            '.'
        );
    }

    $recomputed = $resolver->normalizeTerm(
        $term
    );

    if ($recomputed !== $normalizedTerm) {
        $normalizationMismatches[] = [
            $videoSlug,
            $term,
            $normalizedTerm,
            $recomputed,
        ];
    }

    $key = implode(
        '|||',
        [
            $videoSlug,
            $source,
            $termType,
            $normalizedTerm,
        ]
    );

    if (isset($uniqueRows[$key])) {
        $duplicateInputKeys[] = $key;
        continue;
    }

    $uniqueRows[$key] = [
        'video_slug' => $videoSlug,
        'video_source' => $source,
        'term_type' => $termType,
        'term' => $term,
        'normalized_term' => $normalizedTerm,
    ];
}

fclose($handle);

$rows = array_values(
    $uniqueRows
);

$videoSlugs = array_values(
    array_unique(
        array_column(
            $rows,
            'video_slug'
        )
    )
);

$videos = Video::query()
    ->with('category:id,slug')
    ->whereIn(
        'slug',
        $videoSlugs
    )
    ->get([
        'id',
        'slug',
        'video_source',
        'category_id',
    ])
    ->keyBy('slug');

$missingVideos = [];
$sourceMismatches = [];

foreach ($videoSlugs as $slug) {
    if (! $videos->has($slug)) {
        $missingVideos[] = $slug;
    }
}

foreach ($rows as $row) {
    $video = $videos->get(
        $row['video_slug']
    );

    if ($video === null) {
        continue;
    }

    if (
        $expectedSource !== null &&
        $row['video_source'] !==
            $expectedSource
    ) {
        $sourceMismatches[] = [
            $row['video_slug'],
            $row['video_source'],
            $expectedSource,
            'expected-source',
        ];
    }

    $videoSource = strtolower(
        trim(
            (string) $video->video_source
        )
    );

    if (
        $videoSource !== '' &&
        $videoSource !==
            $row['video_source']
    ) {
        $sourceMismatches[] = [
            $row['video_slug'],
            $row['video_source'],
            $videoSource,
            'video-record',
        ];
    }
}

echo 'INPUT_ROWS=' .
    $inputRows .
    PHP_EOL;

echo 'UNIQUE_ROWS=' .
    count($rows) .
    PHP_EOL;

echo 'VIDEOS_REFERENCED=' .
    count($videoSlugs) .
    PHP_EOL;

echo 'DUPLICATE_INPUT_KEYS=' .
    count($duplicateInputKeys) .
    PHP_EOL;

echo 'NORMALIZATION_MISMATCHES=' .
    count($normalizationMismatches) .
    PHP_EOL;

echo 'MISSING_VIDEOS=' .
    count($missingVideos) .
    PHP_EOL;

echo 'SOURCE_MISMATCHES=' .
    count($sourceMismatches) .
    PHP_EOL;

echo 'DRY_RUN=' .
    ($dryRun ? 'YES' : 'NO') .
    PHP_EOL;

foreach (
    array_slice(
        $normalizationMismatches,
        0,
        20
    ) as $mismatch
) {
    echo 'NORMALIZATION_MISMATCH=' .
        implode('|', $mismatch) .
        PHP_EOL;
}

foreach (
    array_slice(
        $missingVideos,
        0,
        20
    ) as $slug
) {
    echo 'MISSING_VIDEO=' .
        $slug .
        PHP_EOL;
}

foreach (
    array_slice(
        $sourceMismatches,
        0,
        20
    ) as $mismatch
) {
    echo 'SOURCE_MISMATCH=' .
        implode('|', $mismatch) .
        PHP_EOL;
}

if ($duplicateInputKeys !== []) {
    fail(
        'SOURCE_TERM_IMPORT=FAIL duplicate input keys detected.'
    );
}

if ($normalizationMismatches !== []) {
    fail(
        'SOURCE_TERM_IMPORT=FAIL normalization mismatches detected.'
    );
}

if ($missingVideos !== []) {
    fail(
        'SOURCE_TERM_IMPORT=FAIL referenced videos are missing.'
    );
}

if ($sourceMismatches !== []) {
    fail(
        'SOURCE_TERM_IMPORT=FAIL source mismatches detected.'
    );
}

$rowsByVideo = [];

foreach ($rows as $row) {
    $rowsByVideo[
        $row['video_slug']
    ][] = $row;
}

$primaryAliasMatches = 0;
$multiCategoryMatches = 0;
$noAliasMatches = 0;

foreach ($rowsByVideo as $slug => $videoRows) {
    $video = $videos->get($slug);

    if ($video === null) {
        continue;
    }

    $termsByType = [];

    foreach ($videoRows as $row) {
        $termsByType[
            $row['term_type']
        ][] = $row['normalized_term'];
    }

    $matchedCategoryIds = [];

    foreach ($termsByType as $termType => $terms) {
        $categories = $resolver
            ->matchedCategories(
                (string) $video->video_source,
                $termType,
                $terms
            );

        foreach ($categories as $category) {
            $matchedCategoryIds[
                $category->id
            ] = true;
        }
    }

    if ($matchedCategoryIds === []) {
        $noAliasMatches++;
        continue;
    }

    if (count($matchedCategoryIds) > 1) {
        $multiCategoryMatches++;
    }

    if (
        $video->category_id !== null &&
        isset(
            $matchedCategoryIds[
                $video->category_id
            ]
        )
    ) {
        $primaryAliasMatches++;
    }
}

echo 'PRIMARY_ALIAS_MATCH_VIDEOS=' .
    $primaryAliasMatches .
    PHP_EOL;

echo 'MULTI_CATEGORY_MATCH_VIDEOS=' .
    $multiCategoryMatches .
    PHP_EOL;

echo 'NO_ALIAS_MATCH_VIDEOS=' .
    $noAliasMatches .
    PHP_EOL;

if ($dryRun) {
    echo 'ROWS_UPSERTED=0' .
        PHP_EOL;

    echo 'SOURCE_TERM_IMPORT=PASS' .
        PHP_EOL;

    exit(0);
}

$now = now();
$upsertRows = [];

foreach ($rows as $row) {
    $video = $videos->get(
        $row['video_slug']
    );

    if ($video === null) {
        continue;
    }

    $upsertRows[] = [
        'video_id' => $video->id,
        'source' => $row['video_source'],
        'term_type' => $row['term_type'],
        'term' => $row['term'],
        'normalized_term' =>
            $row['normalized_term'],
        'created_at' => $now,
        'updated_at' => $now,
    ];
}

DB::transaction(
    function () use ($upsertRows): void {
        foreach (
            array_chunk(
                $upsertRows,
                1000
            ) as $chunk
        ) {
            DB::table(
                'video_source_terms'
            )->upsert(
                $chunk,
                [
                    'video_id',
                    'source',
                    'term_type',
                    'normalized_term',
                ],
                [
                    'term',
                    'updated_at',
                ]
            );
        }
    }
);

echo 'ROWS_UPSERTED=' .
    count($upsertRows) .
    PHP_EOL;

echo 'SOURCE_TERM_IMPORT=PASS' .
    PHP_EOL;
