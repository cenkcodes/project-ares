<?php

namespace App\Filament\Imports;

use App\Models\Video;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;

class VideoImporter extends Importer
{
    protected static ?string $model = Video::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('title')
                ->label('Title')
                ->requiredMappingForNewRecordsOnly()
                ->rules([
                    'required',
                    'string',
                    'max:' . Video::MAX_TITLE_LENGTH,
                ])
                ->example('Sample Video'),

            ImportColumn::make('slug')
                ->label('Slug')
                ->requiredMapping()
                ->rules([
                    'required',
                    'string',
                    'max:' . Video::MAX_SLUG_LENGTH,
                ])
                ->example('sample-video'),

            ImportColumn::make('description')
                ->label('Description')
                ->ignoreBlankState()
                ->rules([
                    'nullable',
                    'string',
                    'max:' . Video::MAX_DESCRIPTION_LENGTH,
                ])
                ->example('Sample video description'),

            ImportColumn::make('embed_url')
                ->label('Embed URL')
                ->requiredMappingForNewRecordsOnly()
                ->rules([
                    'required',
                    'url',
                    'max:' . Video::MAX_URL_LENGTH,
                ])
                ->example(
                    'https://www.youtube.com/embed/M7lc1UVf-VE'
                ),

            ImportColumn::make('video_source')
                ->label('Video Source')
                ->ignoreBlankState()
                ->rules([
                    'nullable',
                    'string',
                    'max:' . Video::MAX_VIDEO_SOURCE_LENGTH,
                ])
                ->example('youtube'),

            ImportColumn::make('thumbnail')
                ->label('Thumbnail URL')
                ->ignoreBlankState()
                ->rules([
                    'nullable',
                    'url',
                    'max:' . Video::MAX_URL_LENGTH,
                ])
                ->example(
                    'https://img.youtube.com/vi/M7lc1UVf-VE/hqdefault.jpg'
                ),

            ImportColumn::make('duration')
                ->label('Duration')
                ->ignoreBlankState()
                ->numeric()
                ->rules([
                    'nullable',
                    'integer',
                    'min:0',
                    'max:' . Video::MAX_DURATION_SECONDS,
                ])
                ->example('596'),

            ImportColumn::make('category')
                ->label('Category Slug')
                ->relationship(
                    resolveUsing: 'slug'
                )
                ->requiredMappingForNewRecordsOnly()
                ->rules([
                    'required',
                ])
                ->example('milf')
                ->helperText(
                    'Use the category slug, not the category database ID.'
                ),

            ImportColumn::make('views')
                ->label('Views')
                ->ignoreBlankState()
                ->numeric()
                ->rules([
                    'nullable',
                    'integer',
                    'min:0',
                ])
                ->example('0'),

            ImportColumn::make('is_hd')
                ->label('HD')
                ->ignoreBlankState()
                ->boolean()
                ->rules([
                    'nullable',
                    'boolean',
                ])
                ->example('1'),

            ImportColumn::make('is_4k')
                ->label('4K')
                ->ignoreBlankState()
                ->boolean()
                ->rules([
                    'nullable',
                    'boolean',
                ])
                ->example('0'),

            ImportColumn::make('is_featured')
                ->label('Featured')
                ->ignoreBlankState()
                ->boolean()
                ->rules([
                    'nullable',
                    'boolean',
                ])
                ->example('0'),

            ImportColumn::make('is_premium')
                ->label('Premium')
                ->ignoreBlankState()
                ->boolean()
                ->rules([
                    'nullable',
                    'boolean',
                ])
                ->example('0'),

            ImportColumn::make('is_active')
                ->label('Active')
                ->ignoreBlankState()
                ->boolean()
                ->rules([
                    'nullable',
                    'boolean',
                ])
                ->example('1'),
        ];
    }

    public function resolveRecord(): Video
    {
        return Video::firstOrNew([
            'slug' => $this->data['slug'],
        ]);
    }

    protected function beforeCreate(): void
    {
        if (! array_key_exists('views', $this->data)) {
            $this->record->views = 0;
        }

        if (! array_key_exists('is_hd', $this->data)) {
            $this->record->is_hd = false;
        }

        if (! array_key_exists('is_4k', $this->data)) {
            $this->record->is_4k = false;
        }

        if (! array_key_exists('is_featured', $this->data)) {
            $this->record->is_featured = false;
        }

        if (! array_key_exists('is_premium', $this->data)) {
            $this->record->is_premium = false;
        }

        if (! array_key_exists('is_active', $this->data)) {
            $this->record->is_active = true;
        }
    }

    public static function getCompletedNotificationBody(
        Import $import
    ): string {
        $body =
            'Video import completed. ' .
            Number::format(
                $import->successful_rows
            ) .
            ' ' .
            str('row')->plural(
                $import->successful_rows
            ) .
            ' imported.';

        if (
            $failedRowsCount =
                $import->getFailedRowsCount()
        ) {
            $body .=
                ' ' .
                Number::format(
                    $failedRowsCount
                ) .
                ' ' .
                str('row')->plural(
                    $failedRowsCount
                ) .
                ' failed.';
        }

        return $body;
    }
}
