#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Models\VideoProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, 'ERROR=' . $message . PHP_EOL);
    exit($code);
}

function line(string $message = ''): void
{
    echo $message . PHP_EOL;
}

$options = getopt('', ['backend::', 'install', 'rollback-created', 'help']);

if (isset($options['help'])) {
    line('Usage:');
    line('  php eporner-provider-bootstrap.php [--backend=/var/www/project-ares/backend] [--install|--rollback-created]');
    line('');
    line('Without --install the script is read-only.');
    exit(0);
}

$backend = isset($options['backend'])
    ? rtrim((string) $options['backend'], DIRECTORY_SEPARATOR)
    : '/var/www/project-ares/backend';
$install = isset($options['install']);
$rollbackCreated = isset($options['rollback-created']);

if ($install && $rollbackCreated) {
    fail('--install and --rollback-created cannot be used together.', 2);
}

$autoload = $backend . '/vendor/autoload.php';
$bootstrap = $backend . '/bootstrap/app.php';

if (! is_file($autoload)) {
    fail('Laravel autoload not found: ' . $autoload);
}
if (! is_file($bootstrap)) {
    fail('Laravel bootstrap not found: ' . $bootstrap);
}

require $autoload;
$app = require $bootstrap;
$app->make(Kernel::class)->bootstrap();

if (! Schema::hasTable('video_providers')) {
    fail('video_providers table does not exist.');
}

$existing = VideoProvider::query()->where('slug', 'eporner')->first();

if ($rollbackCreated) {
    if ($existing === null) {
        line('EPORNER_PROVIDER_ROLLBACK=PASS_ALREADY_ABSENT');
        exit(0);
    }

    $videoCount = DB::table('videos')
        ->where('video_source', 'eporner')
        ->count();

    if ($videoCount !== 0) {
        fail('Refusing provider rollback because Eporner videos exist: ' . $videoCount);
    }

    $existing->delete();
    line('EPORNER_PROVIDER_ROLLBACK=PASS_DELETED');
    exit(0);
}

if ($existing !== null) {
    line('EPORNER_PROVIDER_EXISTS=YES');
    line('EPORNER_PROVIDER_ID=' . $existing->id);
    line('EPORNER_PROVIDER_ACTIVE=' . ($existing->is_active ? '1' : '0'));
    line('EPORNER_PROVIDER_MONETIZATION=' . ($existing->monetization_enabled ? '1' : '0'));
    line('EPORNER_PROVIDER_HAS_OWN_ADS=' . ($existing->has_own_ads ? '1' : '0'));
    line('EPORNER_PROVIDER_BANNER=' . ($existing->allow_banner_ads ? '1' : '0'));
    line('EPORNER_PROVIDER_BOOTSTRAP=PASS_EXISTING');
    exit(0);
}

line('EPORNER_PROVIDER_EXISTS=NO');

if (! $install) {
    line('EPORNER_PROVIDER_BOOTSTRAP=CHECK_ONLY_MISSING');
    exit(2);
}

$provider = DB::transaction(
    static function (): VideoProvider {
        return VideoProvider::query()->create([
            'name' => 'Eporner',
            'slug' => 'eporner',
            'description' => 'External embedded video provider via official Eporner API v2.',
            'is_active' => true,
            'monetization_enabled' => false,
            'has_own_ads' => true,
            'allow_xurvexa_preroll' => false,
            'allow_xurvexa_midroll' => false,
            'allow_popunder' => false,
            'allow_native_ads' => false,
            'allow_banner_ads' => false,
            'allow_interstitial' => false,
            'monetization_notes' => 'Provider integrated for source diversity. Xurvexa-controlled monetization disabled pending embed/playback and security QA.',
        ]);
    }
);

line('EPORNER_PROVIDER_CREATED=YES');
line('EPORNER_PROVIDER_ID=' . $provider->id);
line('EPORNER_PROVIDER_ACTIVE=' . ($provider->is_active ? '1' : '0'));
line('EPORNER_PROVIDER_MONETIZATION=' . ($provider->monetization_enabled ? '1' : '0'));
line('EPORNER_PROVIDER_HAS_OWN_ADS=' . ($provider->has_own_ads ? '1' : '0'));
line('EPORNER_PROVIDER_BANNER=' . ($provider->allow_banner_ads ? '1' : '0'));
line('EPORNER_PROVIDER_BOOTSTRAP=PASS_CREATED');
