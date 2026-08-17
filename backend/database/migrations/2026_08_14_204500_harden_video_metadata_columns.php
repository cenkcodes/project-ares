<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MAX_TITLE_LENGTH = 1000;
    private const MAX_DESCRIPTION_LENGTH = 100000;
    private const MAX_URL_LENGTH = 8192;
    private const MAX_DURATION_SECONDS = 31536000;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('videos')) {
            return;
        }

        /*
         * This corrective migration targets PostgreSQL.
         *
         * SQLite is used by the automated test suite and
         * receives the desired schema from the base migration.
         */
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        /*
         * Fail early with clear messages before PostgreSQL
         * attempts to add CHECK constraints.
         */
        $this->validateExistingMetadata();

        /*
         * Normalize legacy duration representations such as:
         *
         * 596
         * 09:56
         * 01:09:56
         *
         * into integer seconds.
         */
        $this->normalizeExistingDurations();

        DB::statement(
            <<<'SQL'
            ALTER TABLE videos
                ALTER COLUMN title TYPE TEXT,
                ALTER COLUMN thumbnail TYPE TEXT,
                ALTER COLUMN duration TYPE INTEGER
                    USING NULLIF(BTRIM(duration::text), '')::integer
            SQL
        );

        DB::statement(
            sprintf(
                <<<'SQL'
                ALTER TABLE videos
                    ADD CONSTRAINT videos_title_length_check
                        CHECK (
                            CHAR_LENGTH(BTRIM(title)) >= 1
                            AND CHAR_LENGTH(title) <= %d
                        ),
                    ADD CONSTRAINT videos_description_length_check
                        CHECK (
                            description IS NULL
                            OR CHAR_LENGTH(description) <= %d
                        ),
                    ADD CONSTRAINT videos_embed_url_length_check
                        CHECK (
                            CHAR_LENGTH(BTRIM(embed_url)) >= 1
                            AND CHAR_LENGTH(embed_url) <= %d
                        ),
                    ADD CONSTRAINT videos_thumbnail_length_check
                        CHECK (
                            thumbnail IS NULL
                            OR CHAR_LENGTH(thumbnail) <= %d
                        ),
                    ADD CONSTRAINT videos_duration_range_check
                        CHECK (
                            duration IS NULL
                            OR (
                                duration >= 0
                                AND duration <= %d
                            )
                        ),
                    ADD CONSTRAINT videos_views_non_negative_check
                        CHECK (
                            views >= 0
                        )
                SQL,
                self::MAX_TITLE_LENGTH,
                self::MAX_DESCRIPTION_LENGTH,
                self::MAX_URL_LENGTH,
                self::MAX_URL_LENGTH,
                self::MAX_DURATION_SECONDS,
            )
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('videos')) {
            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        /*
         * Refuse rollback if it would silently truncate data.
         */
        $hasTooLongTitle = DB::table('videos')
            ->whereRaw(
                'CHAR_LENGTH(title) > ?',
                [255]
            )
            ->exists();

        if ($hasTooLongTitle) {
            throw new \RuntimeException(
                'Cannot roll back title to VARCHAR(255) because at least one title is longer than 255 characters.'
            );
        }

        $hasTooLongThumbnail = DB::table('videos')
            ->whereNotNull('thumbnail')
            ->whereRaw(
                'CHAR_LENGTH(thumbnail) > ?',
                [255]
            )
            ->exists();

        if ($hasTooLongThumbnail) {
            throw new \RuntimeException(
                'Cannot roll back thumbnail to VARCHAR(255) because at least one thumbnail URL is longer than 255 characters.'
            );
        }

        DB::statement(
            <<<'SQL'
            ALTER TABLE videos
                DROP CONSTRAINT IF EXISTS videos_title_length_check,
                DROP CONSTRAINT IF EXISTS videos_description_length_check,
                DROP CONSTRAINT IF EXISTS videos_embed_url_length_check,
                DROP CONSTRAINT IF EXISTS videos_thumbnail_length_check,
                DROP CONSTRAINT IF EXISTS videos_duration_range_check,
                DROP CONSTRAINT IF EXISTS videos_views_non_negative_check
            SQL
        );

        DB::statement(
            <<<'SQL'
            ALTER TABLE videos
                ALTER COLUMN duration TYPE VARCHAR(255)
                    USING duration::text,
                ALTER COLUMN thumbnail TYPE VARCHAR(255)
                    USING thumbnail::VARCHAR(255),
                ALTER COLUMN title TYPE VARCHAR(255)
                    USING title::VARCHAR(255)
            SQL
        );
    }

    /**
     * Validate existing PostgreSQL data before changing
     * column types or adding constraints.
     */
    private function validateExistingMetadata(): void
    {
        $hasBlankTitle = DB::table('videos')
            ->whereRaw(
                "BTRIM(title) = ''"
            )
            ->exists();

        if ($hasBlankTitle) {
            throw new \RuntimeException(
                'Cannot harden videos schema because at least one video has an empty or whitespace-only title.'
            );
        }

        $hasTooLongTitle = DB::table('videos')
            ->whereRaw(
                'CHAR_LENGTH(title) > ?',
                [self::MAX_TITLE_LENGTH]
            )
            ->exists();

        if ($hasTooLongTitle) {
            throw new \RuntimeException(
                'Cannot harden videos schema because at least one video title exceeds '
                . self::MAX_TITLE_LENGTH
                . ' characters.'
            );
        }

        $hasTooLongDescription = DB::table('videos')
            ->whereNotNull('description')
            ->whereRaw(
                'CHAR_LENGTH(description) > ?',
                [self::MAX_DESCRIPTION_LENGTH]
            )
            ->exists();

        if ($hasTooLongDescription) {
            throw new \RuntimeException(
                'Cannot harden videos schema because at least one video description exceeds '
                . self::MAX_DESCRIPTION_LENGTH
                . ' characters.'
            );
        }

        $hasBlankEmbedUrl = DB::table('videos')
            ->whereRaw(
                "BTRIM(embed_url) = ''"
            )
            ->exists();

        if ($hasBlankEmbedUrl) {
            throw new \RuntimeException(
                'Cannot harden videos schema because at least one video has an empty or whitespace-only embed URL.'
            );
        }

        $hasTooLongEmbedUrl = DB::table('videos')
            ->whereRaw(
                'CHAR_LENGTH(embed_url) > ?',
                [self::MAX_URL_LENGTH]
            )
            ->exists();

        if ($hasTooLongEmbedUrl) {
            throw new \RuntimeException(
                'Cannot harden videos schema because at least one embed URL exceeds '
                . self::MAX_URL_LENGTH
                . ' characters.'
            );
        }

        $hasTooLongThumbnail = DB::table('videos')
            ->whereNotNull('thumbnail')
            ->whereRaw(
                'CHAR_LENGTH(thumbnail) > ?',
                [self::MAX_URL_LENGTH]
            )
            ->exists();

        if ($hasTooLongThumbnail) {
            throw new \RuntimeException(
                'Cannot harden videos schema because at least one thumbnail URL exceeds '
                . self::MAX_URL_LENGTH
                . ' characters.'
            );
        }

        $hasNegativeViews = DB::table('videos')
            ->where(
                'views',
                '<',
                0
            )
            ->exists();

        if ($hasNegativeViews) {
            throw new \RuntimeException(
                'Cannot harden videos schema because at least one video has a negative views value.'
            );
        }
    }

    /**
     * Convert legacy duration values to integer seconds.
     */
    private function normalizeExistingDurations(): void
    {
        DB::table('videos')
            ->select([
                'id',
                'duration',
            ])
            ->whereNotNull('duration')
            ->orderBy('id')
            ->chunkById(
                500,
                function ($videos): void {
                    foreach ($videos as $video) {
                        $seconds = $this->durationToSeconds(
                            $video->duration,
                            (int) $video->id,
                        );

                        DB::table('videos')
                            ->where(
                                'id',
                                $video->id
                            )
                            ->update([
                                'duration' => $seconds,
                            ]);
                    }
                },
            );
    }

    /**
     * Convert supported duration formats to seconds.
     */
    private function durationToSeconds(
        mixed $value,
        int $videoId,
    ): ?int {
        if ($value === null) {
            return null;
        }

        $duration = trim(
            (string) $value
        );

        if ($duration === '') {
            return null;
        }

        /*
         * Already stored as seconds.
         */
        if (ctype_digit($duration)) {
            return $this->validateDurationRange(
                (int) $duration,
                $videoId,
            );
        }

        $parts = explode(
            ':',
            $duration
        );

        /*
         * MM:SS
         */
        if (
            count($parts) === 2
            && ctype_digit($parts[0])
            && ctype_digit($parts[1])
        ) {
            $minutes = (int) $parts[0];
            $seconds = (int) $parts[1];

            if ($seconds < 60) {
                return $this->validateDurationRange(
                    ($minutes * 60) + $seconds,
                    $videoId,
                );
            }
        }

        /*
         * HH:MM:SS
         */
        if (
            count($parts) === 3
            && ctype_digit($parts[0])
            && ctype_digit($parts[1])
            && ctype_digit($parts[2])
        ) {
            $hours = (int) $parts[0];
            $minutes = (int) $parts[1];
            $seconds = (int) $parts[2];

            if (
                $minutes < 60
                && $seconds < 60
            ) {
                return $this->validateDurationRange(
                    ($hours * 3600)
                    + ($minutes * 60)
                    + $seconds,
                    $videoId,
                );
            }
        }

        throw new \RuntimeException(
            sprintf(
                'Unsupported duration format for video ID %d: %s',
                $videoId,
                $duration,
            )
        );
    }

    /**
     * Validate normalized duration range.
     */
    private function validateDurationRange(
        int $seconds,
        int $videoId,
    ): int {
        if (
            $seconds < 0
            || $seconds > self::MAX_DURATION_SECONDS
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Duration for video ID %d is outside the allowed range: %d seconds.',
                    $videoId,
                    $seconds,
                )
            );
        }

        return $seconds;
    }
};
