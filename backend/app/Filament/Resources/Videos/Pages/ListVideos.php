<?php

namespace App\Filament\Resources\Videos\Pages;

use App\Filament\Imports\VideoImporter;
use App\Filament\Resources\Videos\VideoResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\Rules\File;

class ListVideos extends ListRecords
{
    protected static string $resource =
        VideoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportAction::make('importVideos')
                ->label('Import Videos')
                ->importer(VideoImporter::class)
                ->fileRules([
                    File::types([
                        'csv',
                        'txt',
                    ])->max('5mb'),
                ])
                ->maxRows(5000)
                ->chunkSize(100)
                ->csvDelimiter(','),

            CreateAction::make(),
        ];
    }
}
