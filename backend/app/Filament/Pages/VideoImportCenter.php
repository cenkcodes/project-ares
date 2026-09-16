<?php

namespace App\Filament\Pages;

use App\Jobs\LaunchVideoImportJob;
use App\Models\Category;
use App\Models\VideoImportJob;
use App\Models\VideoProvider;
use App\Services\VideoImportRunner;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class VideoImportCenter extends Page
{
    protected static string | BackedEnum | null $navigationIcon =
        'heroicon-o-arrow-down-tray';

    protected static ?string $navigationLabel =
        'Video Import Center';

    protected static string | UnitEnum | null $navigationGroup =
        'Video Management';

    protected static ?int $navigationSort = 20;

    protected static ?string $title =
        'Video Import Center';

    protected string $view =
        'filament.pages.video-import-center';

    public ?array $data = [];

    public int $refreshKey = 0;

    public function mount(): void
    {
        $this->form->fill([
            'source' => 'eporner',
            'category' => null,
            'mode' => 'prepare-only',
            'minimum_count' => 1000,
            'target_count' => 1200,
            'search_depth' => 'auto',
            'import_confirmation' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Import Job')
                    ->description(
                        'Uses the existing protected provider pipeline. '
                        . 'The queue only launches a detached server process; '
                        . 'the long import does not run inside the 60-second '
                        . 'queue worker timeout.'
                    )
                    ->schema([
                        Select::make('source')
                            ->label('Source')
                            ->options(fn (): array => $this->sourceOptions())
                            ->required()
                            ->native(false),

                        Select::make('category')
                            ->label('Category')
                            ->options(fn (): array => $this->categoryOptions())
                            ->searchable()
                            ->required()
                            ->native(false),

                        Select::make('mode')
                            ->label('Mode')
                            ->options([
                                'prepare-only' => 'Prepare Only',
                                'import' => 'Import',
                            ])
                            ->helperText(
                                'Prepare Only is the safe default. Import writes '
                                . 'accepted videos and source terms to production.'
                            )
                            ->required()
                            ->native(false),

                        TextInput::make('minimum_count')
                            ->label('Minimum Accepted')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(5000)
                            ->required(),

                        TextInput::make('target_count')
                            ->label('Target Candidates')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(5000)
                            ->helperText(
                                'Must be equal to or higher than Minimum Accepted.'
                            )
                            ->required(),

                        Select::make('search_depth')
                            ->label('Search Depth')
                            ->options([
                                'auto' => 'Auto (40 pages maximum)',
                                '10' => '10 pages',
                                '20' => '20 pages',
                                '40' => '40 pages',
                            ])
                            ->helperText(
                                'Auto uses the current standard of 40 pages. '
                                . 'The collector stops early when target is reached.'
                            )
                            ->required()
                            ->native(false),

                        Select::make('import_confirmation')
                            ->label('Real Import Confirmation')
                            ->options([
                                'confirmed' => 'Yes — run a real production import',
                            ])
                            ->placeholder('Not confirmed')
                            ->helperText(
                                'Required only when Mode is Import. '
                                . 'Leave unconfirmed for Prepare Only.'
                            )
                            ->native(false),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function startJob(): void
    {
        if (VideoImportJob::query()->active()->exists()) {
            Notification::make()
                ->title('Another video import job is already active')
                ->body('Wait for the current job to finish before starting another.')
                ->warning()
                ->send();

            return;
        }

        $data = $this->form->getState();

        $source = (string) ($data['source'] ?? '');
        $category = (string) ($data['category'] ?? '');
        $mode = (string) ($data['mode'] ?? 'prepare-only');
        $minimum = (int) ($data['minimum_count'] ?? 0);
        $target = (int) ($data['target_count'] ?? 0);
        $searchDepth = (string) ($data['search_depth'] ?? 'auto');
        $importConfirmation = (string) (
            $data['import_confirmation'] ?? ''
        );
        $maxPages = $searchDepth === 'auto'
            ? 40
            : (int) $searchDepth;

        if ($target < $minimum) {
            throw ValidationException::withMessages([
                'data.target_count' =>
                    'Target Candidates cannot be lower than Minimum Accepted.',
            ]);
        }

        if (! in_array($mode, ['import', 'prepare-only'], true)) {
            throw ValidationException::withMessages([
                'data.mode' => 'Invalid import mode.',
            ]);
        }

        if (
            $mode === 'import'
            && $importConfirmation !== 'confirmed'
        ) {
            throw ValidationException::withMessages([
                'data.import_confirmation' =>
                    'Real Import requires explicit confirmation before the job can start.',
            ]);
        }

        if (! in_array($maxPages, [10, 20, 40], true)) {
            throw ValidationException::withMessages([
                'data.search_depth' => 'Invalid search depth.',
            ]);
        }

        $supportedSources = VideoImportRunner::supportedSources();

        if (! in_array($source, $supportedSources, true)) {
            throw ValidationException::withMessages([
                'data.source' =>
                    'This source is not supported by the current Video Import Center runner.',
            ]);
        }

        $provider = VideoProvider::query()
            ->where('slug', $source)
            ->where('is_active', true)
            ->first();

        if ($provider === null) {
            throw ValidationException::withMessages([
                'data.source' =>
                    'Selected source is not an active video provider.',
            ]);
        }

        $categoryModel = Category::query()
            ->where('slug', $category)
            ->where('is_active', true)
            ->first();

        if ($categoryModel === null) {
            throw ValidationException::withMessages([
                'data.category' => 'Selected category is not active.',
            ]);
        }

        $job = VideoImportJob::query()->create([
            'source' => $source,
            'category_slug' => $category,
            'mode' => $mode,
            'minimum_count' => $minimum,
            'target_count' => $target,
            'max_pages' => $maxPages,
            'status' => VideoImportJob::STATUS_QUEUED,
            'stage' => 'queued',
            'progress_current' => 0,
            'progress_total' => $target,
            'message' => 'Waiting for launcher queue worker.',
            'created_by' => is_numeric(auth()->id())
                ? (int) auth()->id()
                : null,
        ]);

        LaunchVideoImportJob::dispatch($job->id);

        $this->data['import_confirmation'] = null;

        Notification::make()
            ->title('Video import job queued')
            ->body(
                strtoupper($source) . ' / ' . $category
                . ' / ' . $mode
                . ' / minimum ' . number_format($minimum)
            )
            ->success()
            ->send();

        $this->refreshStatus();
    }

    public function refreshStatus(): void
    {
        $this->refreshKey++;
    }

    protected function getViewData(): array
    {
        $activeJob = VideoImportJob::query()
            ->active()
            ->latest('id')
            ->first();

        $latestJob = $activeJob ?? VideoImportJob::query()
            ->latest('id')
            ->first();

        $recentJobs = VideoImportJob::query()
            ->latest('id')
            ->limit(10)
            ->get();

        return [
            'activeJob' => $activeJob,
            'latestJob' => $latestJob,
            'recentJobs' => $recentJobs,
            'logTail' => $latestJob !== null
                ? $this->tailLog($latestJob->log_path)
                : '',
        ];
    }

    private function sourceOptions(): array
    {
        return VideoProvider::query()
            ->where('is_active', true)
            ->whereIn('slug', VideoImportRunner::supportedSources())
            ->orderBy('name')
            ->pluck('name', 'slug')
            ->all();
    }

    private function categoryOptions(): array
    {
        return Category::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'slug')
            ->all();
    }

    private function tailLog(?string $path): string
    {
        if (
            $path === null
            || $path === ''
            || ! File::exists($path)
        ) {
            return '';
        }

        $size = File::size($path);
        $readLength = min($size, 65536);

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        try {
            if ($size > $readLength) {
                fseek($handle, -$readLength, SEEK_END);
            }

            $contents = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        if ($contents === false) {
            return '';
        }

        $lines = preg_split('/\R/', trim($contents)) ?: [];

        return implode("\n", array_slice($lines, -60));
    }
}
