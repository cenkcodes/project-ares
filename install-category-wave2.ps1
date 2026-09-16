$ErrorActionPreference = "Stop"

$ProjectRoot = "C:\Projects\Project-Ares"
$LocalBatch = Join-Path $ProjectRoot "ops\video-import\xvideos-batch.php"
$Key = "C:\Users\Pc\.ssh\xurvexa_mojo_rsa"
$SshTarget = "ubuntu@185.94.236.129"

$RemoteBackend = "/var/www/project-ares/backend"
$RemoteOps = "/var/www/project-ares/ops/video-import"
$RemoteBatch = "$RemoteOps/xvideos-batch.php"
$RemoteTemp = "/tmp/xvideos-batch-wave2.php"
$RemoteBackup = "$RemoteOps/xvideos-batch.php.bak-20260831-category-wave2"

$ExpectedOldHash = "dfad3582af6feb14ac233e85b0ff8e613f0fa4549ddca6f3a6cc3816d32c5a6b"
$ExpectedNewHash = "44f5acb72247fee6ad77e737f95a0c53efa40d0396d334c90cabd2ed52000475"

Write-Host ""
Write-Host "===== LOCAL WAVE-2 FILE CHECK ====="

if (-not (Test-Path -LiteralPath $LocalBatch)) {
    throw "Missing local orchestrator: $LocalBatch"
}

$LocalHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $LocalBatch).Hash.ToLowerInvariant()
Write-Host "LOCAL_XVIDEOS_BATCH_SHA256=$LocalHash"

if ($LocalHash -ne $ExpectedNewHash) {
    throw "Local xvideos-batch.php hash mismatch."
}

Write-Host "LOCAL_WAVE2_FILE=PASS"

Write-Host ""
Write-Host "===== PRODUCTION BASELINE / BACKUP ====="

$RemoteOldHash = (
    ssh -i $Key $SshTarget "sha256sum '$RemoteBatch' | cut -d' ' -f1"
).Trim().ToLowerInvariant()

Write-Host "PRODUCTION_OLD_SHA256=$RemoteOldHash"

if ($RemoteOldHash -eq $ExpectedOldHash) {
    ssh -i $Key $SshTarget "sudo cp '$RemoteBatch' '$RemoteBackup' && sudo chmod 0755 '$RemoteBackup'"
    if ($LASTEXITCODE -ne 0) {
        throw "Production backup failed."
    }
    Write-Host "CATEGORY_WAVE2_BACKUP=PASS"

    scp -i $Key $LocalBatch "${SshTarget}:${RemoteTemp}"
    if ($LASTEXITCODE -ne 0) {
        throw "Upload failed."
    }

    ssh -i $Key $SshTarget "sudo install -m 0755 '$RemoteTemp' '$RemoteBatch' && rm -f '$RemoteTemp'"
    if ($LASTEXITCODE -ne 0) {
        throw "Production orchestrator replacement failed."
    }
}
elseif ($RemoteOldHash -eq $ExpectedNewHash) {
    Write-Host "CATEGORY_WAVE2_BACKUP=ALREADY_INSTALLED"
}
else {
    throw "Unexpected production orchestrator hash: $RemoteOldHash"
}

Write-Host ""
Write-Host "===== ORCHESTRATOR VALIDATION ====="

ssh -i $Key $SshTarget "php -l '$RemoteBatch'"
if ($LASTEXITCODE -ne 0) {
    throw "Production PHP syntax validation failed."
}

$RemoteNewHash = (
    ssh -i $Key $SshTarget "sha256sum '$RemoteBatch' | cut -d' ' -f1"
).Trim().ToLowerInvariant()

Write-Host "PRODUCTION_NEW_SHA256=$RemoteNewHash"

if ($RemoteNewHash -ne $ExpectedNewHash) {
    throw "Production orchestrator hash mismatch after install."
}

Write-Host "CATEGORY_WAVE2_ORCHESTRATOR=PASS"

Write-Host ""
Write-Host "===== CREATE WAVE-2 CATEGORIES / EXACT ALIASES ====="

$RemoteScript = @'
set -euo pipefail

cd /var/www/project-ares/backend

cat > /tmp/xurvexa-category-wave2-install.php <<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require '/var/www/project-ares/backend/vendor/autoload.php';

$app = require '/var/www/project-ares/backend/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$categories = [
    'blowjob' => 'Blowjob',
    'cumshot' => 'Cumshot',
    'interracial' => 'Interracial',
    'handjob' => 'Handjob',
    'pornstar' => 'Pornstar',
    'threesome' => 'Threesome',
];

$now = now();

DB::transaction(function () use ($categories, $now): void {
    foreach ($categories as $slug => $name) {
        $categoryId = DB::table('categories')
            ->where('slug', $slug)
            ->value('id');

        if ($categoryId === null) {
            $categoryId = DB::table('categories')->insertGetId([
                'name' => $name,
                'slug' => $slug,
                'description' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('categories')
                ->where('id', $categoryId)
                ->update([
                    'name' => $name,
                    'is_active' => true,
                    'updated_at' => $now,
                ]);
        }

        DB::table('category_aliases')->updateOrInsert(
            [
                'source' => '*',
                'alias_type' => 'tag',
                'normalized_alias' => $slug,
            ],
            [
                'category_id' => $categoryId,
                'alias' => $slug,
                'priority' => 100,
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }
});

echo "CATEGORY_WAVE2_DB_INSTALL=PASS\n";
PHP

php /tmp/xurvexa-category-wave2-install.php
rm -f /tmp/xurvexa-category-wave2-install.php

php artisan optimize:clear >/dev/null
echo "CATEGORY_WAVE2_CACHE_CLEAR=PASS"

php artisan tinker --execute='
use Illuminate\Support\Facades\DB;

$slugs = [
    "blowjob",
    "cumshot",
    "interracial",
    "handjob",
    "pornstar",
    "threesome",
];

echo "CATEGORIES" . PHP_EOL;

DB::table("categories")
    ->whereIn("slug", $slugs)
    ->orderBy("id")
    ->get(["id", "name", "slug", "is_active"])
    ->each(
        fn ($row) =>
            print(
                $row->id . "|" .
                $row->name . "|" .
                $row->slug . "|" .
                (int) $row->is_active .
                PHP_EOL
            )
    );

echo "ALIASES" . PHP_EOL;

DB::table("category_aliases as ca")
    ->join("categories as c", "c.id", "=", "ca.category_id")
    ->where("ca.source", "*")
    ->where("ca.alias_type", "tag")
    ->whereIn("ca.normalized_alias", $slugs)
    ->orderBy("ca.id")
    ->get([
        "c.slug as category_slug",
        "ca.alias",
        "ca.normalized_alias",
        "ca.is_active",
    ])
    ->each(
        fn ($row) =>
            print(
                $row->category_slug . "|" .
                $row->alias . "|" .
                $row->normalized_alias . "|" .
                (int) $row->is_active .
                PHP_EOL
            )
    );

$categoryCount = DB::table("categories")
    ->whereIn("slug", $slugs)
    ->where("is_active", true)
    ->count();

$aliasCount = DB::table("category_aliases")
    ->where("source", "*")
    ->where("alias_type", "tag")
    ->whereIn("normalized_alias", $slugs)
    ->where("is_active", true)
    ->count();

echo "WAVE2_CATEGORY_COUNT=" . $categoryCount . PHP_EOL;
echo "WAVE2_ALIAS_COUNT=" . $aliasCount . PHP_EOL;

if ($categoryCount !== 6 || $aliasCount !== 6) {
    throw new RuntimeException("Wave-2 category/alias count mismatch.");
}

echo "CATEGORY_WAVE2_FINAL=PASS" . PHP_EOL;
'

echo "CATEGORY_WAVE2_INSTALL=PASS"
'@

$RemoteScript | ssh -i $Key $SshTarget "bash -s"
if ($LASTEXITCODE -ne 0) {
    throw "Wave-2 category DB install or final validation failed."
}

Write-Host ""
Write-Host "===== WAVE-2 CATEGORY FOUNDATION COMPLETE ====="
Write-Host "CATEGORY_WAVE2_INSTALL=PASS"
