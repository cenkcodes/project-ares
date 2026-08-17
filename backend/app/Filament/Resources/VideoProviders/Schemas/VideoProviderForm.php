<?php

namespace App\Filament\Resources\VideoProviders\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VideoProviderForm
{
    public static function configure(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make('Provider Identity')
                    ->description(
                        'Basic information used to identify '
                        . 'the external video provider.'
                    )
                    ->schema([
                        TextInput::make('name')
                            ->label('Provider Name')
                            ->placeholder('XVideos')
                            ->required()
                            ->maxLength(100),

                        TextInput::make('slug')
                            ->label('Provider Slug')
                            ->placeholder('xvideos')
                            ->helperText(
                                'Must match the video_source '
                                . 'value used by imported videos.'
                            )
                            ->required()
                            ->maxLength(100)
                            ->unique(ignoreRecord: true),

                        Textarea::make('description')
                            ->label('Description')
                            ->rows(4)
                            ->maxLength(10000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Provider Status')
                    ->description(
                        'Controls whether the provider and '
                        . 'its Xurvexa monetization rules '
                        . 'are active.'
                    )
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Provider Active')
                            ->helperText(
                                'Disable this if the provider '
                                . 'should no longer participate '
                                . 'in provider-based decisions.'
                            )
                            ->default(true),

                        Toggle::make('monetization_enabled')
                            ->label('Monetization Enabled')
                            ->helperText(
                                'Master monetization switch '
                                . 'for this provider.'
                            )
                            ->default(true),

                        Toggle::make('has_own_ads')
                            ->label('Provider Has Own Ads')
                            ->helperText(
                                'Enable when the embedded '
                                . 'provider already serves '
                                . 'its own advertising.'
                            )
                            ->default(false),
                    ])
                    ->columns(3),

                Section::make('Xurvexa Ad Permissions')
                    ->description(
                        'Controls which Xurvexa advertising '
                        . 'formats may be used with videos '
                        . 'from this provider.'
                    )
                    ->schema([
                        Toggle::make('allow_xurvexa_preroll')
                            ->label('Allow Xurvexa Pre-roll')
                            ->helperText(
                                'For providers with their own '
                                . 'ads, the decision engine can '
                                . 'skip this to prevent double '
                                . 'pre-roll.'
                            )
                            ->default(true),

                        Toggle::make('allow_xurvexa_midroll')
                            ->label('Allow Xurvexa Mid-roll')
                            ->default(false),

                        Toggle::make('allow_popunder')
                            ->label('Allow Popunder / Clickunder')
                            ->default(true),

                        Toggle::make('allow_native_ads')
                            ->label('Allow Native Ads')
                            ->default(true),

                        Toggle::make('allow_banner_ads')
                            ->label('Allow Banner Ads')
                            ->default(true),

                        Toggle::make('allow_interstitial')
                            ->label('Allow Interstitial')
                            ->default(false),
                    ])
                    ->columns(2),

                Section::make('Monetization Notes')
                    ->description(
                        'Internal notes about provider ad '
                        . 'behavior, restrictions or '
                        . 'commercial decisions.'
                    )
                    ->schema([
                        Textarea::make('monetization_notes')
                            ->label('Notes')
                            ->rows(5)
                            ->maxLength(10000)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
