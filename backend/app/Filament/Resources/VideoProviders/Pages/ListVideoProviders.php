<?php

namespace App\Filament\Resources\VideoProviders\Pages;

use App\Filament\Resources\VideoProviders\VideoProviderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVideoProviders extends ListRecords
{
    protected static string $resource = VideoProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
