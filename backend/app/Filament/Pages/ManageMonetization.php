<?php

namespace App\Filament\Pages;

use App\Models\MonetizationSetting;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use UnitEnum;

class ManageMonetization extends Page
{
    protected static string | BackedEnum | null $navigationIcon =
        'heroicon-o-banknotes';

    protected static ?string $navigationLabel =
        'Monetization';

    protected static string | UnitEnum | null $navigationGroup =
        'Monetization';

    protected static ?int $navigationSort = 30;

    protected static ?string $title =
        'Monetization';

    protected string $view =
        'filament.pages.manage-monetization';

    public ?array $data = [];

    /**
     * Load the single global monetization record.
     */
    public function mount(): void
    {
        $settings = MonetizationSetting::global();

        if ($settings === null) {
            throw new \RuntimeException(
                'Global monetization settings are missing. '
                . 'Run: php artisan db:seed '
                . '--class=MonetizationSettingSeeder'
            );
        }

        $this->form->fill(
            $settings->attributesToArray()
        );
    }

    /**
     * Configure the global monetization form.
     */
    public function form(
        Schema $schema
    ): Schema {
        return $schema
            ->components([
                Section::make(
                    'Global Control'
                )
                    ->description(
                        'Master controls for all '
                        . 'Xurvexa-managed advertising.'
                    )
                    ->schema([
                        Toggle::make(
                            'master_enabled'
                        )
                            ->label(
                                'Master Monetization'
                            )
                            ->helperText(
                                'Emergency kill switch. '
                                . 'Turn this off to stop all '
                                . 'Xurvexa-controlled ads.'
                            ),

                        Select::make(
                            'profile'
                        )
                            ->label(
                                'Monetization Profile'
                            )
                            ->options([
                                MonetizationSetting::PROFILE_CONSERVATIVE =>
                                    'Conservative',

                                MonetizationSetting::PROFILE_BALANCED =>
                                    'Balanced',

                                MonetizationSetting::PROFILE_REVENUE_MAX =>
                                    'Revenue Max',
                            ])
                            ->required()
                            ->native(false),

                        Toggle::make(
                            'mobile_ads_enabled'
                        )
                            ->label(
                                'Mobile Ads'
                            ),

                        Toggle::make(
                            'desktop_ads_enabled'
                        )
                            ->label(
                                'Desktop Ads'
                            ),
                    ])
                    ->columns(2),

                Section::make(
                    'Native & Banner Ads'
                )
                    ->description(
                        'Low-interruption advertising '
                        . 'used throughout catalog and '
                        . 'video pages.'
                    )
                    ->schema([
                        Toggle::make(
                            'native_ads_enabled'
                        )
                            ->label(
                                'Native Ads'
                            ),

                        TextInput::make(
                            'native_ad_interval'
                        )
                            ->label(
                                'Native Ad Interval'
                            )
                            ->helperText(
                                'Number of organic video '
                                . 'cards between native '
                                . 'ad placements.'
                            )
                            ->integer()
                            ->minValue(1)
                            ->maxValue(1000)
                            ->required(),

                        Toggle::make(
                            'banner_ads_enabled'
                        )
                            ->label(
                                'Banner Ads'
                            ),
                    ])
                    ->columns(2),

                Section::make(
                    'Pre-roll'
                )
                    ->description(
                        'Xurvexa-controlled pre-roll. '
                        . 'Provider-level rules are '
                        . 'evaluated separately.'
                    )
                    ->schema([
                        Toggle::make(
                            'preroll_enabled'
                        )
                            ->label(
                                'Pre-roll Enabled'
                            ),

                        Toggle::make(
                            'skip_preroll_when_provider_has_ads'
                        )
                            ->label(
                                'Skip When Provider Has Ads'
                            )
                            ->helperText(
                                'Prevents double pre-roll '
                                . 'when an embedded provider '
                                . 'already serves ads.'
                            ),

                        Toggle::make(
                            'preroll_on_first_video'
                        )
                            ->label(
                                'Pre-roll On First Video'
                            )
                            ->helperText(
                                'Balanced launch profile '
                                . 'keeps this disabled.'
                            ),

                        TextInput::make(
                            'preroll_skip_after_seconds'
                        )
                            ->label(
                                'Skip After'
                            )
                            ->suffix(
                                'seconds'
                            )
                            ->integer()
                            ->minValue(0)
                            ->maxValue(600)
                            ->required(),

                        TextInput::make(
                            'preroll_max_per_session'
                        )
                            ->label(
                                'Max Per Session'
                            )
                            ->integer()
                            ->minValue(0)
                            ->maxValue(100)
                            ->required(),

                        TextInput::make(
                            'preroll_cooldown_minutes'
                        )
                            ->label(
                                'Cooldown'
                            )
                            ->suffix(
                                'minutes'
                            )
                            ->integer()
                            ->minValue(0)
                            ->maxValue(10080)
                            ->required(),
                    ])
                    ->columns(2),

                Section::make(
                    'Popunder / Clickunder'
                )
                    ->description(
                        'Higher-revenue disruptive '
                        . 'format. Keep frequency '
                        . 'controlled to protect UX.'
                    )
                    ->schema([
                        Toggle::make(
                            'popunder_enabled'
                        )
                            ->label(
                                'Popunder Enabled'
                            ),

                        TextInput::make(
                            'popunder_trigger_after_interactions'
                        )
                            ->label(
                                'Trigger After Interactions'
                            )
                            ->integer()
                            ->minValue(1)
                            ->maxValue(1000)
                            ->required(),

                        TextInput::make(
                            'popunder_frequency_minutes'
                        )
                            ->label(
                                'Frequency Cap'
                            )
                            ->suffix(
                                'minutes'
                            )
                            ->helperText(
                                '1440 minutes = 24 hours.'
                            )
                            ->integer()
                            ->minValue(0)
                            ->maxValue(525600)
                            ->required(),

                        TextInput::make(
                            'popunder_max_per_session'
                        )
                            ->label(
                                'Max Per Session'
                            )
                            ->integer()
                            ->minValue(0)
                            ->maxValue(100)
                            ->required(),

                        TextInput::make(
                            'popunder_max_per_day'
                        )
                            ->label(
                                'Max Per Day'
                            )
                            ->integer()
                            ->minValue(0)
                            ->maxValue(100)
                            ->required(),

                        Toggle::make(
                            'popunder_mobile_enabled'
                        )
                            ->label(
                                'Mobile Popunder'
                            ),

                        Toggle::make(
                            'popunder_desktop_enabled'
                        )
                            ->label(
                                'Desktop Popunder'
                            ),
                    ])
                    ->columns(2),

                Section::make(
                    'Mid-roll & Interstitial'
                )
                    ->description(
                        'Higher-interruption formats. '
                        . 'Balanced launch keeps both '
                        . 'disabled.'
                    )
                    ->schema([
                        Toggle::make(
                            'midroll_enabled'
                        )
                            ->label(
                                'Mid-roll Enabled'
                            ),

                        Toggle::make(
                            'interstitial_enabled'
                        )
                            ->label(
                                'Interstitial Enabled'
                            ),

                        TextInput::make(
                            'interstitial_trigger_after_interactions'
                        )
                            ->label(
                                'Interstitial Trigger'
                            )
                            ->suffix(
                                'interactions'
                            )
                            ->integer()
                            ->minValue(1)
                            ->maxValue(1000)
                            ->required(),

                        TextInput::make(
                            'interstitial_frequency_minutes'
                        )
                            ->label(
                                'Interstitial Frequency'
                            )
                            ->suffix(
                                'minutes'
                            )
                            ->integer()
                            ->minValue(0)
                            ->maxValue(525600)
                            ->required(),

                        TextInput::make(
                            'interstitial_max_per_session'
                        )
                            ->label(
                                'Interstitial Max Per Session'
                            )
                            ->integer()
                            ->minValue(0)
                            ->maxValue(100)
                            ->required(),
                    ])
                    ->columns(2),

                Section::make(
                    'User Experience Protection'
                )
                    ->description(
                        'Shared limits prevent multiple '
                        . 'disruptive formats from '
                        . 'overloading one session.'
                    )
                    ->schema([
                        TextInput::make(
                            'session_interruption_budget'
                        )
                            ->label(
                                'Session Interruption Budget'
                            )
                            ->helperText(
                                'Pre-roll, popunder and '
                                . 'interstitial consume '
                                . 'this shared budget.'
                            )
                            ->integer()
                            ->minValue(0)
                            ->maxValue(100)
                            ->required(),

                        Toggle::make(
                            'autoplay_sound_ads_enabled'
                        )
                            ->label(
                                'Autoplay Sound Ads'
                            )
                            ->helperText(
                                'Keep disabled unless '
                                . 'there is a specific '
                                . 'tested reason to enable it.'
                            ),

                        Toggle::make(
                            'ad_event_tracking_enabled'
                        )
                            ->label(
                                'Ad Event Tracking'
                            )
                            ->helperText(
                                'Required for future '
                                . 'revenue, retention and '
                                . 'A/B analysis.'
                            ),
                    ])
                    ->columns(2),

                Section::make(
                    'Administrative Notes'
                )
                    ->schema([
                        Textarea::make(
                            'notes'
                        )
                            ->label(
                                'Notes'
                            )
                            ->rows(5)
                            ->maxLength(10000)
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Persist the global monetization configuration.
     */
    public function save(): void
    {
        $settings = MonetizationSetting::global();

        if ($settings === null) {
            throw new \RuntimeException(
                'Global monetization settings are missing.'
            );
        }

        $data = $this->form->getState();

        /*
         * settings_key is intentionally not editable
         * from this page. This remains the single
         * global settings record.
         */
        unset(
            $data['settings_key']
        );

        $settings->update(
            $data
        );

        $settings->refresh();

        $this->form->fill(
            $settings->attributesToArray()
        );

        Notification::make()
            ->title(
                'Monetization settings saved'
            )
            ->success()
            ->send();
    }
}
