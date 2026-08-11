<?php

namespace App\Filament\Resources\ImportHistory;

use App\Filament\Resources\ImportHistory\Pages\ListImportHistory;
use App\Filament\Resources\ImportHistory\Tables\ImportHistoryTable;
use BackedEnum;
use Filament\Actions\Imports\Models\Import;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ImportHistoryResource extends Resource
{
    protected static ?string $model = Import::class;

    protected static string|BackedEnum|null $navigationIcon =
        Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel =
        'Import History';

    protected static ?string $modelLabel =
        'Import';

    protected static ?string $pluralModelLabel =
        'Import History';

    protected static ?string $recordTitleAttribute =
        'file_name';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([]);
    }

    public static function table(Table $table): Table
    {
        return ImportHistoryTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportHistory::route('/'),
        ];
    }
}
