<?php

namespace App\Filament\Resources\VideoProviders;

use App\Filament\Resources\VideoProviders\Pages\CreateVideoProvider;
use App\Filament\Resources\VideoProviders\Pages\EditVideoProvider;
use App\Filament\Resources\VideoProviders\Pages\ListVideoProviders;
use App\Filament\Resources\VideoProviders\Schemas\VideoProviderForm;
use App\Filament\Resources\VideoProviders\Tables\VideoProvidersTable;
use App\Models\VideoProvider;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class VideoProviderResource extends Resource
{
    protected static ?string $model = VideoProvider::class;

    protected static string | BackedEnum | null $navigationIcon =
        Heroicon::OutlinedServerStack;

    protected static string | UnitEnum | null $navigationGroup =
        'Monetization';

    protected static ?string $navigationLabel =
        'Video Providers';

    protected static ?string $modelLabel =
        'Video Provider';

    protected static ?string $pluralModelLabel =
        'Video Providers';

    protected static ?int $navigationSort = 31;

    protected static ?string $recordTitleAttribute =
        'name';

    public static function form(
        Schema $schema
    ): Schema {
        return VideoProviderForm::configure(
            $schema
        );
    }

    public static function table(
        Table $table
    ): Table {
        return VideoProvidersTable::configure(
            $table
        );
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' =>
                ListVideoProviders::route('/'),

            'create' =>
                CreateVideoProvider::route('/create'),

            'edit' =>
                EditVideoProvider::route('/{record}/edit'),
        ];
    }
}
