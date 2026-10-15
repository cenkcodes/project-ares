<?php

namespace App\Filament\Pages;

use App\Models\VideoHealthRun;
use App\Services\VideoHealth\VideoHealthLauncher;
use App\Services\VideoHealth\VideoHealthRunCoordinator;
use App\Services\VideoWorkloadAdmission;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;
use UnitEnum;

class VideoHealthMonitor extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-heart';
    protected static ?string $navigationLabel = 'Video Health Monitor';
    protected static string | UnitEnum | null $navigationGroup = 'Video Management';
    protected static ?int $navigationSort = 21;
    protected static ?string $title = 'Video Health Monitor';
    protected string $view = 'filament.pages.video-health-monitor';

    public ?array $data = [];
    public int $refreshKey = 0;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof FilamentUser && $user->canAccessPanel(Filament::getPanel('admin'));
    }

    public function mount(): void
    {
        $this->form->fill(['provider' => 'all', 'mode' => 'audit-only', 'confirmation' => null]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Manual health run')
                ->description('The admitted run launches as a detached process. Closing this page does not stop it. Automatic deactivation is disabled.')
                ->schema([
                    Select::make('provider')->options([
                        'all' => 'All providers', 'eporner' => 'Eporner', 'xvideos' => 'XVideos',
                        'xnxx' => 'XNXX', 'redtube' => 'RedTube',
                    ])->required()->native(false)->live()
                        ->afterStateUpdated(fn (Set $set) => $set('confirmation', null)),
                    Select::make('mode')->options([
                        'audit-only' => 'Audit Only', 'audit-deactivate' => 'Audit + Deactivate Broken',
                    ])->required()->native(false)->live()
                        ->afterStateUpdated(fn (Set $set) => $set('confirmation', null)),
                    Select::make('confirmation')->label('Deactivation confirmation')
                        ->options(['confirmed' => 'I confirm that newly reconfirmed BROKEN videos may be deactivated.'])
                        ->placeholder('Not confirmed')->native(false)
                        ->helperText('Required for Audit + Deactivate Broken. Confirmation is cleared when the provider or mode changes or a run is admitted.'),
                ])->columns(2),
        ])->statePath('data');
    }

    public function runNow(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        $deactivate = $data['mode'] === VideoHealthRun::MODE_AUDIT_DEACTIVATE;
        if ($deactivate && ($data['confirmation'] ?? null) !== 'confirmed') {
            throw ValidationException::withMessages(['data.confirmation' => 'Explicit deactivation confirmation is required.']);
        }
        try {
            $result = app(VideoHealthRunCoordinator::class)->admitManual([
                'provider' => $data['provider'], 'mode' => $data['mode'],
                'created_by' => Filament::auth()->id(),
                'confirmed_at' => $deactivate ? now()->toIso8601String() : null,
            ]);
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->title('Unable to admit health run')->body('No launch was attempted. Contact an administrator.')->danger()->send();

            return;
        }
        if ($result['run'] === null) {
            $message = match ($result['reason']) {
                VideoWorkloadAdmission::ACTIVE_IMPORT => 'A video import is active. Wait for it to finish.',
                VideoWorkloadAdmission::ACTIVE_HEALTH => 'A video health run is active. Wait for it to finish or review its launch state.',
                VideoWorkloadAdmission::AUTOMATIC_DEACTIVATION_DISABLED => 'Automatic deactivation is disabled.',
                default => 'The run request is invalid. Check the selected provider, mode and confirmation.',
            };
            Notification::make()->title('Health run not admitted')->body($message)->warning()->send();

            return;
        }
        $this->data['confirmation'] = null;
        $this->launchExisting($result['run']->id);
        $this->refreshStatus();
    }

    public function retryLaunch(int $runId): void
    {
        abort_unless(static::canAccess(), 403);
        $run = VideoHealthRun::query()->useWritePdo()->find($runId);
        if ($run === null || !$this->canRetry($run)) {
            Notification::make()->title('Launch retry unavailable')->body('Only an eligible queued run may be retried.')->warning()->send();

            return;
        }
        $this->launchExisting($run->id);
        $this->refreshStatus();
    }

    public function canRetry(VideoHealthRun $run): bool
    {
        return $run->status === VideoHealthRun::STATUS_QUEUED
            && ($run->mode === VideoHealthRun::MODE_AUDIT_ONLY
                || ($run->mode === VideoHealthRun::MODE_AUDIT_DEACTIVATE
                    && $run->trigger === VideoHealthRun::TRIGGER_MANUAL && $run->confirmed_at !== null));
    }

    private function launchExisting(int $runId): void
    {
        try {
            $result = app(VideoHealthLauncher::class)->launch($runId);
            if (($result['launched'] ?? null) !== true) {
                Notification::make()->title('Launch requires operator review')->body('Launch was not confirmed. Do not start another run or force a retry.')->danger()->send();

                return;
            }
            Notification::make()->title('Detached health process launched')->body('Run #'.$runId.' was launched. The process owns scan status and completion.')->success()->send();
        } catch (Throwable $exception) {
            report($exception);
            // Only these existing launcher messages guarantee cleanup/no child.
            $retryable = in_array($exception->getMessage(), [
                'Detached process creation failed; the queued run may be retried.',
                'Cannot persist launch reservation; no child was launched.',
            ], true);
            Notification::make()->title($retryable ? 'Health process did not start' : 'Launch requires operator review')
                ->body($retryable
                    ? 'Run #'.$runId.' remains queued. Use Retry Launch on that run; do not create another run.'
                    : 'Run #'.$runId.' may have a launch reservation or a started child. Operator review is required before retrying; do not force launch or create another run.')
                ->danger()->send();
        }
    }

    public function refreshStatus(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->refreshKey++;
    }

    protected function getViewData(): array
    {
        $recentRuns = VideoHealthRun::query()->useWritePdo()->latest('id')->limit(10)->get();

        return ['latestRun' => $recentRuns->first(), 'recentRuns' => $recentRuns];
    }
}
