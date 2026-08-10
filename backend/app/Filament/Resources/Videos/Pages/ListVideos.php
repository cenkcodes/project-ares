<?php

namespace App\Filament\Resources\Videos\Pages;

use App\Filament\Imports\VideoImporter;
use App\Filament\Resources\Videos\VideoResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

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
                ->maxRows(5000)
                ->chunkSize(100),

            CreateAction::make(),
        ];
    }
}
