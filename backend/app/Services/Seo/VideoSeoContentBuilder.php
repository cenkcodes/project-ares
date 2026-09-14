<?php

namespace App\Services\Seo;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PDOException;
use Throwable;

class VideoSeoContentBuilder
{
    public const GENERATOR_VERSION = 'video-seo-content-v1.2.4';

    private const MAX_CATEGORIES = 4;

    private const ADVISORY_LOCK_KEY = 7483102601;

    /**
     * Only input IDs are used. Current values are refreshed after locking.
     * No relationships are lazy-loaded.
     * CREATED/UPDATED describe planned operations when dryRun is true.
     */
    public function buildBatch(
        Collection $videos,
        bool $dryRun = false,
        bool $force = false
    ): array {
        $videos = $videos->unique('id')->values();
        $result = [
            'selected' => $videos->count(),
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped_unchanged' => 0,
            'quality_hold' => 0,
            'failed' => 0,
            'results' => [],
            'errors' => [],
            'fatal_error' => false,
            'fatal_error_type' => '',
            'fatal_error_message' => '',
        ];

        if ($videos->isEmpty()) {
            return $result;
        }

        $emptyResult = $result;
        $connection = null;
        $ownsTransaction = false;

        try {
            $connection = DB::connection();
            if (
                $connection->getDriverName() !== 'pgsql'
                || $connection->transactionLevel() !== 0
            ) {
                throw new \RuntimeException('Video SEO batches require their own outer PostgreSQL transaction.');
            }

            $ownsTransaction = true;

            // One attempt: never replay a transaction callback automatically.
            return $connection->transaction(function () use ($connection, $videos, $dryRun, $force, $result): array {
                $lock = $connection->selectOne(
                    'SELECT pg_try_advisory_xact_lock(?) AS acquired',
                    [self::ADVISORY_LOCK_KEY],
                    false
                );
                if (! in_array($lock->acquired, [true, 1, '1', 't'], true)) {
                    $this->fatal($result, 'lock_unavailable', 'Another Video SEO batch holds the global lock.');

                    return $result;
                }

                // All snapshots and writes use this transaction's writer connection.
                $ids = $videos->pluck('id')->all();
                $currentVideos = $connection->table('videos')
                    ->useWritePdo()
                    ->whereIn('id', $ids)
                    ->get([
                        'id', 'title', 'is_hd', 'is_4k',
                        'is_active', 'category_id',
                    ])
                    ->keyBy('id');
                $videos = collect($ids)->map(fn ($id) => $currentVideos->get($id));
                if ($videos->contains(null)) {
                    throw new \RuntimeException('A selected video disappeared during preparation; retry the batch.');
                }
                $primaryCategories = $connection->table('categories')->useWritePdo()
                    ->whereIn('id', $videos->pluck('category_id')->filter()->unique())
                    ->where('is_active', true)
                    ->get(['id', 'name', 'slug'])
                    ->keyBy('id');

                $memberships = $connection->table('seo_video_category_memberships as membership')->useWritePdo()
                    ->join('categories as category', 'category.id', '=', 'membership.category_id')
                    ->whereIn('membership.video_id', $ids)
                    ->where('category.is_active', true)
                    ->orderBy('membership.video_id')
                    ->orderByDesc('membership.evidence_count')
                    ->orderBy('category.id')
                    ->get(['membership.video_id', 'category.id', 'category.name'])
                    ->groupBy('video_id');

                $existing = $connection->table('video_seo_contents')->useWritePdo()
                    ->whereIn('video_id', $ids)
                    ->get(['video_id', 'generator_version', 'source_fingerprint'])
                    ->keyBy('video_id');

                foreach ($videos as $video) {
                    $id = (int) $video->id;

                    $persisting = false;
                    try {
                        $primary = $primaryCategories->get($video->category_id);
                        $primaryName = $this->normalize($primary?->name ?? '');
                        $categories = [];

                        if ($primaryName !== '') {
                            $categories[(int) $primary->id] = $primaryName;
                        }

                        foreach ($memberships->get($id, collect()) as $membership) {
                            $name = $this->normalize($membership->name);

                            if ($name !== '' && ! in_array($name, $categories, true)) {
                                $categories[(int) $membership->id] = $name;
                            }

                            if (count($categories) >= ($primaryName !== '' ? self::MAX_CATEGORIES : self::MAX_CATEGORIES - 1)) {
                                break;
                            }
                        }

                        $titleQualityReason = $this->sourceTitleQualityReason($video->title ?? '');
                        $sourceTitle = $this->whitespace($video->title ?? '');
                        $proseTitle = $this->proseSafeTitle($sourceTitle);

                        // Only effective output inputs: HD is irrelevant when 4K is set.
                        // Ordered canonical identities and names form the classification snapshot.
                        $input = [
                            'active' => (bool) $video->is_active,
                            'title' => $proseTitle,
                            'title_quality_reason' => $titleQualityReason,
                            // Original emoji/length eligibility affects meta output.
                            // Store its outcome, not decorative source characters.
                            'meta_title_eligible' => $this->metaTitleEligible($sourceTitle)
                                && $this->metaTitleEligible($proseTitle),
                            'quality' => $video->is_4k ? '4K' : ($video->is_hd ? 'HD' : null),
                            'primary_category_id' => $primaryName !== '' ? (int) $primary->id : null,
                            'primary_category' => $primaryName,
                            'primary_category_slug' => $primary?->slug ?? '',
                            'category_ids' => array_keys($categories),
                            'categories' => array_values($categories),
                        ];

                        $fingerprint = $this->hash($input);
                        $description = $titleQualityReason === null ? $this->describe($input) : '';
                        $meta = $titleQualityReason === null ? $this->metaDescription($input) : '';
                        $status = $input['active']
                            && $input['title'] !== ''
                            && $input['primary_category'] !== ''
                            && $description !== ''
                            && $meta !== ''
                                ? 'published'
                                : 'quality_hold';

                        $contentHash = $this->hash([
                            'description' => $description,
                            'meta_description' => $meta,
                            'quality_status' => $status,
                        ]);

                        $row = $existing->get($id);
                        $unchanged = ! $force
                            && $row !== null
                            && $row->generator_version === self::GENERATOR_VERSION
                            && $row->source_fingerprint === $fingerprint;

                        if ($unchanged) {
                            $action = $dryRun ? 'would_skip' : 'skip_unchanged';
                            $result['skipped_unchanged']++;
                        } else {
                            if (! $dryRun) {
                                $now = now();

                                // Atomic conflict handling respects UNIQUE(video_id).
                                // created_at is only inserted; updated_at is refreshed.
                                // The same connection creates a savepoint for this row.
                                $persisting = true;
                                $connection->transaction(function () use (
                                    $connection, $id, $description, $meta, $status,
                                    $fingerprint, $contentHash, $now
                                ): void {
                                    $connection->table('video_seo_contents')->upsert([
                                        [
                                            'video_id' => $id,
                                            'created_at' => $now,
                                            'updated_at' => $now,
                                            'description' => $description,
                                            'meta_description' => $meta,
                                            'quality_status' => $status,
                                            'generator_version' => self::GENERATOR_VERSION,
                                            'source_fingerprint' => $fingerprint,
                                            'content_hash' => $contentHash,
                                            'generated_at' => $now,
                                            'published_at' => $status === 'published' ? $now : null,
                                        ],
                                    ], ['video_id'], [
                                        'description',
                                        'meta_description',
                                        'quality_status',
                                        'generator_version',
                                        'source_fingerprint',
                                        'content_hash',
                                        'generated_at',
                                        'published_at',
                                        'updated_at',
                                    ]);
                                }, 1);
                                $persisting = false;
                            }

                            $counter = $row === null ? 'created' : 'updated';
                            $result[$counter]++;
                            $action = $dryRun
                                ? ($row === null ? 'would_create' : 'would_update')
                                : $counter;
                        }

                        $result['processed']++;
                        $result['quality_hold'] += (int) ($status === 'quality_hold');
                        $result['results'][$id] = [
                            'action' => $action,
                            'quality_status' => $status,
                            'source_fingerprint' => $fingerprint,
                            'content_hash' => $contentHash,
                        ];
                    } catch (Throwable $exception) {
                        // Only SQLSTATE 22/23 row errors may survive a savepoint
                        // rollback. Connection, transaction and unknown DB errors abort.
                        if (
                            ($persisting || $this->hasDatabaseException($exception))
                            && ! $this->isRowDataException($exception)
                        ) {
                            throw $exception;
                        }
                        $result['failed']++;
                        $result['errors'][$id] = $exception->getMessage();
                    }
                }

                return $result;
            }, 1);
        } catch (Throwable $exception) {
            // A fatal transaction must never report rolled-back writes as committed.
            // Discard a possibly unusable session after rollback/commit failure.
            // Never disconnect a caller-owned transaction rejected above.
            if ($ownsTransaction) {
                $connection->disconnect();
            }
            $result = $emptyResult;
            $this->fatal($result, 'transaction_error', $exception->getMessage());

            return $result;
        }
    }

    private function hasDatabaseException(Throwable $exception): bool
    {
        do {
            if ($exception instanceof PDOException) {
                return true;
            }
            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return false;
    }

    private function isRowDataException(Throwable $exception): bool
    {
        // Inspect the outermost DB error first: a failed rollback is fatal
        // even if its original cause was a recoverable row constraint.
        do {
            if ($exception instanceof PDOException) {
                $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());

                return in_array(substr($state, 0, 2), ['22', '23'], true);
            }
            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return false;
    }

    private function fatal(array &$result, string $type, string $message): void
    {
        $result['fatal_error'] = true;
        $result['fatal_error_type'] = $result['fatal_error_type'] === ''
            ? $type : $result['fatal_error_type'] . '+' . $type;
        $result['fatal_error_message'] = trim(
            $result['fatal_error_message'] . ' ' . $message
        );
    }

    private function describe(array $input): string
    {
        if ($input['title'] === '') {
            return '';
        }

        $title = '“' . $input['title'] . '”';
        $primary = $input['primary_category'];
        $secondary = $this->secondaryCategories($input);
        $parts = [];
        $categoryEcho = $this->isCategoryEcho($input);

        // Structure follows metadata shape, never a hash or synonym rotation.
        if ($primary === '') {
            // Non-publishable input still receives only factual title wording.
            $parts[] = 'Video title: ' . $title . '.';
        } elseif (! $categoryEcho && $this->titleHasInformation($input['title'])) {
            $parts[] = $title . ' is listed in the ' . $primary . ' category.';
            if ($secondary !== []) {
                $parts[] = 'Additional classifications include ' . $this->nameList($secondary) . '.';
            }
        } else {
            $parts[] = $secondary !== []
                ? 'This ' . $primary . ' video is also classified under ' . $this->nameList($secondary) . '.'
                : 'This video is listed in the ' . $primary . ' category.';
            if (! $categoryEcho) {
                $parts[] = 'Its catalog title is ' . $title . '.';
            }
        }

        if ($input['quality'] !== null) {
            $parts[] = 'The video is marked ' . $input['quality'] . '.';
        }

        return $this->whitespace(implode(' ', $parts));
    }

    private function secondaryCategories(array $input): array
    {
        return array_slice(array_values(array_filter(
            $input['categories'],
            fn (string $name): bool => $name !== $input['primary_category']
        )), 0, 3);
    }

    private function nameList(array $names): string
    {
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names) . ' and ' . $last;
    }

    private function metaDescription(array $input): string
    {
        $primary = $input['primary_category'];
        if ($primary === '') {
            return '';
        }

        $secondary = $this->secondaryCategories($input);
        $quality = $input['quality'];
        $title = $input['meta_title_eligible'] && ! $this->isCategoryEcho($input)
            ? $input['title'] : null;

        // Preserve all classification terms before considering shorter subsets.
        // An optional title never displaces a selected canonical category.
        $candidates = [];
        if ($title !== null) {
            // Quality is optional and must not displace an eligible title.
            foreach ($quality !== null ? [$quality, null] : [null] as $titleQuality) {
                $candidate = $this->whitespace(
                    $this->metaCandidate($title, $primary, $secondary, $titleQuality)
                );
                if (mb_strlen($candidate, 'UTF-8') <= 120) {
                    $candidates[] = $candidate;
                }
            }
        }
        for ($count = count($secondary); $count >= 0; $count--) {
            $selected = array_slice($secondary, 0, $count);
            $candidates[] = $this->metaCandidate(null, $primary, $selected, $quality);
            if ($quality !== null) {
                $candidates[] = $this->metaCandidate(null, $primary, $selected, null);
            }
        }

        foreach ($candidates as $candidate) {
            // Do not alter canonical phrases to make an HTML-bearing candidate fit.
            if ($candidate !== strip_tags($candidate)) {
                continue;
            }
            $candidate = $this->whitespace($candidate);
            if ($candidate !== '' && mb_strlen($candidate, 'UTF-8') <= 155) {
                return $candidate;
            }
        }

        // Unusually long canonical names produce a hold, not a broken clause.
        return '';
    }

    private function metaCandidate(
        ?string $title,
        string $primary,
        array $secondary,
        ?string $quality
    ): string {
        $text = ($quality !== null ? $quality . ' ' : '') . $primary . ' video';
        if ($title !== null) {
            $text .= ' titled “' . $title . '”';
        }
        if ($secondary !== []) {
            $text .= ($title !== null ? ', classified under ' : ' classified under ')
                . $this->nameList($secondary);
        }

        return $text . '.';
    }

    private function titleHasInformation(string $title): bool
    {
        $length = mb_strlen($title, 'UTF-8');
        $lettersAndNumbers = preg_match_all('/[\\p{L}\\p{N}]/u', $title);

        // Conservative shape checks only; no semantic claims or NLP.
        // Long informative titles remain intact in the description.
        return $length >= 5
            && $lettersAndNumbers !== false
            && $lettersAndNumbers >= 5
            && $lettersAndNumbers / $length >= 0.5;
    }

    private function isCategoryEcho(array $input): bool
    {
        $titleKey = $this->categoryComparisonKey($input['title']);

        return $titleKey !== '' && (
            $titleKey === $this->categoryComparisonKey($input['primary_category'])
            || $titleKey === $this->categoryComparisonKey($input['primary_category_slug'])
        );
    }

    private function categoryComparisonKey(string $text): string
    {
        return preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower(trim($text), 'UTF-8')) ?? '';
    }

    private function sourceTitleQualityReason(string $sourceTitle): ?string
    {
        // Inspect before whitespace normalization can erase control characters.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $sourceTitle) === 1) {
            return 'control_character';
        }

        $title = $this->whitespace($sourceTitle);

        // UTF-8 bytes for common visible encoding-corruption signatures.
        // These are targeted markers, not a generic non-ASCII restriction.
        foreach (["\xC3\x83", "\xC3\x82", "\xC3\xA2\xE2\x82\xAC", "\xC3\xB0\xC5\xB8"] as $signature) {
            if (str_contains($title, $signature)) {
                return 'mojibake';
            }
        }

        if (str_contains($title, "\xEF\xBF\xBD")) {
            return 'replacement_character';
        }
        if (preg_match('/<\/?[a-z][^>]*>|&(?:amp|quot|apos|lt|gt|nbsp);/i', $title) === 1) {
            return 'html_residue';
        }
        if (preg_match('/\b(?:watch more at|watch full video at|full video at|click here)\b/i', $title) === 1) {
            return 'promo_residue';
        }
        if ($this->proseSafeTitle($title) === '') {
            return 'empty_prose_title';
        }

        return null;
    }

    private function proseSafeTitle(string $title): string
    {
        // A separator preserves word boundaries where an emoji separated words.
        $title = preg_replace(
            '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]+/u',
            ' ',
            $title
        ) ?? '';
        $title = preg_replace('/[\x{FE0F}\x{200D}\x{20E3}]/u', '', $title) ?? '';
        $title = preg_replace('/([!?])\1+/u', '$1', $title) ?? '';
        $title = $this->whitespace($title);
        $title = preg_replace('/\s+([,.;:?!\)])/u', '$1', $title) ?? '';

        return $this->whitespace($title);
    }

    private function metaTitleEligible(string $title): bool
    {
        return $this->titleHasInformation($title)
            && mb_strlen($title, 'UTF-8') <= 60
            && preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', $title) === 0
            && $title === strip_tags($title);
    }

    private function whitespace(string $text): string
    {
        return trim(preg_replace('/\\s+/u', ' ', $text) ?? '');
    }

    private function normalize(string $text): string
    {
        $text = strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }
}
