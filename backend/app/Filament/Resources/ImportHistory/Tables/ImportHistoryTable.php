<?php

namespace App\Filament\Resources\ImportHistory\Tables;

use Filament\Actions\Imports\Models\Import;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ImportHistoryTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('file_name')
                    ->label('File')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('importer')
                    ->label('Importer')
                    ->formatStateUsing(
                        fn (?string $state): string =>
                            $state
                                ? class_basename($state)
                                : '-'
                    ),

                TextColumn::make('user.name')
                    ->label('User')
                    ->placeholder('-'),

                TextColumn::make('total_rows')
                    ->label('Total')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('processed_rows')
                    ->label('Processed')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('successful_rows')
                    ->label('Successful')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('failed_rows_count')
                    ->label('Failed')
                    ->counts('failedRows')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->state(
                        fn (Import $record): string =>
                            match (true) {
                                $record->completed_at !== null =>
                                    'Completed',

                                $record->processed_rows > 0 =>
                                    'Processing',

                                default =>
                                    'Pending',
                            }
                    )
                    ->badge()
                    ->color(
                        fn (string $state): string =>
                            match ($state) {
                                'Completed' => 'success',
                                'Processing' => 'warning',
                                default => 'gray',
                            }
                    ),

                TextColumn::make('created_at')
                    ->label('Started')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->defaultSort(
                'created_at',
                'desc'
            );
    }
}
