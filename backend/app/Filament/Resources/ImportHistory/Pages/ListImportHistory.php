<?php

namespace App\Filament\Resources\ImportHistory\Pages;

use App\Filament\Resources\ImportHistory\ImportHistoryResource;
use Filament\Resources\Pages\ListRecords;

class ListImportHistory extends ListRecords
{
    protected static string $resource =
        ImportHistoryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
