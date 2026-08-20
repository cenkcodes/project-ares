<?php

namespace Tests\Feature;

use App\Filament\Imports\VideoImporter;
use App\Models\Category;
use App\Models\Video;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VideoImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_category_slug_is_imported_as_relationship(): void
    {
        $category = $this->createCategory();

        $importer = new VideoImporter(
            new Import(),
            $this->columnMap(),
            [],
        );

        $importer(
            $this->validRow()
        );

        $this->assertDatabaseHas(
            'videos',
            [
                'slug' => 'importer-valid-video',
                'category_id' => $category->id,
            ],
        );
    }

    public function test_category_mapping_is_required_for_new_records(): void
    {
        $columnMap = $this->columnMap();

        unset($columnMap['category']);

        $importer = new VideoImporter(
            new Import(),
            $columnMap,
            [],
        );

        try {
            $importer(
                $this->validRow()
            );

            $this->fail(
                'Import should fail when category is not mapped.'
            );
        } catch (ValidationException) {
            $this->assertDatabaseMissing(
                'videos',
                [
                    'slug' => 'importer-valid-video',
                ],
            );
        }
    }

    public function test_unknown_category_slug_is_rejected(): void
    {
        $importer = new VideoImporter(
            new Import(),
            $this->columnMap(),
            [],
        );

        try {
            $importer(
                $this->validRow([
                    'category' => 'missing-category',
                ])
            );

            $this->fail(
                'Import should fail for an unknown category slug.'
            );
        } catch (ValidationException) {
            $this->assertDatabaseMissing(
                'videos',
                [
                    'slug' => 'importer-valid-video',
                ],
            );
        }
    }

    public function test_title_at_maximum_length_is_accepted(): void
    {
        $this->createCategory();

        $title = str_repeat(
            'A',
            Video::MAX_TITLE_LENGTH
        );

        $importer = new VideoImporter(
            new Import(),
            $this->columnMap(),
            [],
        );

        $importer(
            $this->validRow([
                'title' => $title,
                'slug' => 'title-at-maximum-length',
            ])
        );

        $this->assertDatabaseHas(
            'videos',
            [
                'slug' => 'title-at-maximum-length',
                'title' => $title,
            ],
        );
    }

    public function test_title_above_maximum_length_is_rejected(): void
    {
        $this->createCategory();

        $this->assertImportRejected(
            $this->validRow([
                'title' => str_repeat(
                    'A',
                    Video::MAX_TITLE_LENGTH + 1
                ),
                'slug' => 'title-above-maximum-length',
            ])
        );
    }

    public function test_embed_url_at_maximum_length_is_accepted(): void
    {
        $this->createCategory();

        $embedUrl = $this->urlOfLength(
            Video::MAX_URL_LENGTH
        );

        $this->assertSame(
            Video::MAX_URL_LENGTH,
            strlen($embedUrl)
        );

        $importer = new VideoImporter(
            new Import(),
            $this->columnMap(),
            [],
        );

        $importer(
            $this->validRow([
                'slug' => 'embed-url-at-maximum-length',
                'embed_url' => $embedUrl,
            ])
        );

        $this->assertDatabaseHas(
            'videos',
            [
                'slug' => 'embed-url-at-maximum-length',
                'embed_url' => $embedUrl,
            ],
        );
    }

    public function test_embed_url_above_maximum_length_is_rejected(): void
    {
        $this->createCategory();

        $embedUrl = $this->urlOfLength(
            Video::MAX_URL_LENGTH + 1
        );

        $this->assertSame(
            Video::MAX_URL_LENGTH + 1,
            strlen($embedUrl)
        );

        $this->assertImportRejected(
            $this->validRow([
                'slug' => 'embed-url-above-maximum-length',
                'embed_url' => $embedUrl,
            ])
        );
    }

    public function test_thumbnail_at_maximum_length_is_accepted(): void
    {
        $this->createCategory();

        $thumbnailUrl = $this->urlOfLength(
            Video::MAX_URL_LENGTH
        );

        $this->assertSame(
            Video::MAX_URL_LENGTH,
            strlen($thumbnailUrl)
        );

        $importer = new VideoImporter(
            new Import(),
            $this->columnMap([
                'thumbnail' => 'thumbnail',
            ]),
            [],
        );

        $importer(
            $this->validRow([
                'slug' => 'thumbnail-at-maximum-length',
                'thumbnail' => $thumbnailUrl,
            ])
        );

        $this->assertDatabaseHas(
            'videos',
            [
                'slug' => 'thumbnail-at-maximum-length',
                'thumbnail' => $thumbnailUrl,
            ],
        );
    }

    public function test_thumbnail_above_maximum_length_is_rejected(): void
    {
        $this->createCategory();

        $thumbnailUrl = $this->urlOfLength(
            Video::MAX_URL_LENGTH + 1
        );

        $this->assertSame(
            Video::MAX_URL_LENGTH + 1,
            strlen($thumbnailUrl)
        );

        $this->assertImportRejected(
            $this->validRow([
                'slug' => 'thumbnail-above-maximum-length',
                'thumbnail' => $thumbnailUrl,
            ]),
            $this->columnMap([
                'thumbnail' => 'thumbnail',
            ]),
        );
    }

    public function test_duration_at_maximum_value_is_accepted(): void
    {
        $this->createCategory();

        $importer = new VideoImporter(
            new Import(),
            $this->columnMap([
                'duration' => 'duration',
            ]),
            [],
        );

        $importer(
            $this->validRow([
                'slug' => 'duration-at-maximum-value',
                'duration' => Video::MAX_DURATION_SECONDS,
            ])
        );

        $this->assertDatabaseHas(
            'videos',
            [
                'slug' => 'duration-at-maximum-value',
                'duration' => Video::MAX_DURATION_SECONDS,
            ],
        );
    }

    public function test_duration_above_maximum_value_is_rejected(): void
    {
        $this->createCategory();

        $this->assertImportRejected(
            $this->validRow([
                'slug' => 'duration-above-maximum-value',
                'duration' => Video::MAX_DURATION_SECONDS + 1,
            ]),
            $this->columnMap([
                'duration' => 'duration',
            ]),
        );
    }

    private function createCategory(): Category
    {
        return Category::create([
            'name' => 'Test Category',
            'slug' => 'test-category',
            'description' => 'Importer test category.',
            'is_active' => true,
        ]);
    }

    private function columnMap(
        array $additionalColumns = []
    ): array {
        return array_merge(
            [
                'title' => 'title',
                'slug' => 'slug',
                'embed_url' => 'embed_url',
                'category' => 'category',
            ],
            $additionalColumns,
        );
    }

    private function validRow(
        array $overrides = []
    ): array {
        return array_merge(
            [
                'title' => 'Importer Valid Video',
                'slug' => 'importer-valid-video',
                'embed_url' =>
                    'https://example.com/embed/importer-valid-video',
                'category' => 'test-category',
            ],
            $overrides,
        );
    }

    private function urlOfLength(
        int $length
    ): string {
        $prefix = 'https://example.com/';

        if ($length < strlen($prefix)) {
            throw new \InvalidArgumentException(
                'Requested URL length is shorter than the URL prefix.'
            );
        }

        return $prefix . str_repeat(
            'a',
            $length - strlen($prefix)
        );
    }

    private function assertImportRejected(
        array $row,
        ?array $columnMap = null
    ): void {
        $importer = new VideoImporter(
            new Import(),
            $columnMap ?? $this->columnMap(),
            [],
        );

        try {
            $importer($row);

            $this->fail(
                'Import should have been rejected by validation.'
            );
        } catch (ValidationException) {
            $this->assertDatabaseMissing(
                'videos',
                [
                    'slug' => $row['slug'],
                ],
            );
        }
    }
}
