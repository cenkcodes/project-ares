<?php

namespace App\Filament\Resources\VideoProviders\Pages;

use App\Filament\Resources\VideoProviders\VideoProviderResource;
use Filament\Resources\Pages\EditRecord;

class EditVideoProvider extends EditRecord
{
    protected static string $resource =
        VideoProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
