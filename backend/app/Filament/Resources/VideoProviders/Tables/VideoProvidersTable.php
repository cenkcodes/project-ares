<?php

namespace App\Filament\Resources\VideoProviders\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class VideoProvidersTable
{
    public static function configure(
        Table $table
    ): Table {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Provider')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slug')
                    ->label('Source Slug')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('monetization_enabled')
                    ->label('Monetization')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('has_own_ads')
                    ->label('Own Ads')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('allow_xurvexa_preroll')
                    ->label('Pre-roll')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('allow_popunder')
                    ->label('Popunder')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('allow_native_ads')
                    ->label('Native')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('allow_banner_ads')
                    ->label('Banner')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('allow_xurvexa_midroll')
                    ->label('Mid-roll')
                    ->boolean()
                    ->sortable()
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),

                IconColumn::make('allow_interstitial')
                    ->label('Interstitial')
                    ->boolean()
                    ->sortable()
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),

                TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Provider Active'),

                TernaryFilter::make('monetization_enabled')
                    ->label('Monetization Enabled'),

                TernaryFilter::make('has_own_ads')
                    ->label('Provider Has Own Ads'),

                TernaryFilter::make('allow_xurvexa_preroll')
                    ->label('Xurvexa Pre-roll Allowed'),

                TernaryFilter::make('allow_popunder')
                    ->label('Popunder Allowed'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort(
                'name'
            );
    }
}
