<?php

use App\Models\VideoImportJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(
        Inspiring::quote()
    );
})->purpose(
    'Display an inspiring quote'
);

/*
|--------------------------------------------------------------------------
| Provider-independent Video SEO automation
|--------------------------------------------------------------------------
|
| Canonical video and source-term changes are captured by PostgreSQL dirty
| triggers. Automatic processing is paused while Video Import Center has an
| active job because provider batches persist canonical video rows before the
| final source-term phase. This prevents premature SEO publication from an
| incomplete canonical snapshot.
|
| The incremental worker also requires a short quiet period after the latest
| dirty signal. Video SEO lock contention is requeued rather than dropped, and
| the daily full reconciliation remains the crash/recovery safety net.
|
*/

$noActiveVideoImport =
    fn (): bool =>
        !VideoImportJob::query()
            ->active()
            ->exists();

Schedule::command(
    'seo:process-video-memberships --limit=1000 --lease=900 --settle=60'
)
    ->everyMinute()
    ->when(
        $noActiveVideoImport
    )
    ->withoutOverlapping(
        20
    );

Schedule::command(
    'seo:build-video-contents --all --limit=5000'
)
    ->dailyAt(
        '04:20'
    )
    ->when(
        $noActiveVideoImport
    )
    ->withoutOverlapping(
        180
    );
