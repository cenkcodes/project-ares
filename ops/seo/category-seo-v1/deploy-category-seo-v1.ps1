$ErrorActionPreference = 'Stop'

$Key = 'C:\Users\Pc\.ssh\xurvexa_mojo_rsa'
$Target = 'ubuntu@185.94.236.129'
$Root = 'C:\Projects\Project-Ares'
$PackageRoot = Join-Path $Root 'ops\seo\category-seo-v1'
$Stage = Join-Path $PackageRoot 'stage'

$MigrationName = '2026_09_01_195500_add_meta_description_to_categories_table.php'
$StageMigration = Join-Path $Stage $MigrationName
$StageCategory = Join-Path $Stage 'Category.php'
$StageIndex = Join-Path $Stage 'index.blade.php'
$Content = Join-Path $PackageRoot 'category-seo-content-v1.json'

$ExpectedMigrationHash = '10b17dd9afd7d1e4e8891827fed642da54b998562b535c85b66abb6c18bdcec2'
$ExpectedCategoryHash = '34ae14faf158a21ec57bdc0db13c7fda41ee1af65f53d9c61f839244622b0039'
$ExpectedIndexHash = 'e8cc3fedc34ad60efcec964aa89896f5792d767e085dc0c3135acd4ea1028336'
$ExpectedContentHash = 'ee88692aa4d16acd239dba5ba5c594c70b467de20caa5fcf7972eb77e7362b24'

$CurrentProductionCategoryHash = 'cf28f319bf5bfacfff12f9b4dd5f210a549c9c02a31e9690afc3ee834f17185c'
$CurrentProductionIndexHash = 'd65731b6eadd28505a0951a7817c8d53bbff45da0cbb2602d2979253926c937b'

function Assert-FileHash {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Path,

        [Parameter(Mandatory = $true)]
        [string] $Expected
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        throw "Missing file: $Path"
    }

    $Actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLowerInvariant()

    Write-Host "LOCAL_SHA256|$Path|$Actual"

    if ($Actual -ne $Expected) {
        throw "SHA256 mismatch: $Path"
    }
}

Write-Host ''
Write-Host '===== 1. LOCAL PACKAGE PRECHECK ====='

Assert-FileHash -Path $StageMigration -Expected $ExpectedMigrationHash
Assert-FileHash -Path $StageCategory -Expected $ExpectedCategoryHash
Assert-FileHash -Path $StageIndex -Expected $ExpectedIndexHash
Assert-FileHash -Path $Content -Expected $ExpectedContentHash

Write-Host 'LOCAL_PACKAGE_PRECHECK=PASS'

Write-Host ''
Write-Host '===== 2. UPLOAD STAGED FILES ====='

scp -i $Key $StageMigration "${Target}:/tmp/$MigrationName"
if ($LASTEXITCODE -ne 0) { throw 'Migration upload failed.' }

scp -i $Key $StageCategory "${Target}:/tmp/Category.php"
if ($LASTEXITCODE -ne 0) { throw 'Category.php upload failed.' }

scp -i $Key $StageIndex "${Target}:/tmp/index.blade.php"
if ($LASTEXITCODE -ne 0) { throw 'index.blade.php upload failed.' }

scp -i $Key $Content "${Target}:/tmp/category-seo-content-v1.json"
if ($LASTEXITCODE -ne 0) { throw 'SEO content upload failed.' }

Write-Host 'UPLOAD=PASS'

$RemoteScript = @'
set -euo pipefail

BACKEND="/var/www/project-ares/backend"
OPS="/var/www/project-ares/ops"
SEO_OPS="$OPS/seo"

MIGRATION_NAME="2026_09_01_195500_add_meta_description_to_categories_table.php"
MIGRATION_DST="$BACKEND/database/migrations/$MIGRATION_NAME"
CATEGORY_DST="$BACKEND/app/Models/Category.php"
INDEX_DST="$BACKEND/resources/views/videos/index.blade.php"
CONTENT_DST="$SEO_OPS/category-seo-content-v1.json"

TMP_MIGRATION="/tmp/$MIGRATION_NAME"
TMP_CATEGORY="/tmp/Category.php"
TMP_INDEX="/tmp/index.blade.php"
TMP_CONTENT="/tmp/category-seo-content-v1.json"

EXPECTED_MIGRATION_HASH="10b17dd9afd7d1e4e8891827fed642da54b998562b535c85b66abb6c18bdcec2"
EXPECTED_CATEGORY_HASH="34ae14faf158a21ec57bdc0db13c7fda41ee1af65f53d9c61f839244622b0039"
EXPECTED_INDEX_HASH="e8cc3fedc34ad60efcec964aa89896f5792d767e085dc0c3135acd4ea1028336"
EXPECTED_CONTENT_HASH="ee88692aa4d16acd239dba5ba5c594c70b467de20caa5fcf7972eb77e7362b24"

CURRENT_CATEGORY_HASH="cf28f319bf5bfacfff12f9b4dd5f210a549c9c02a31e9690afc3ee834f17185c"
CURRENT_INDEX_HASH="d65731b6eadd28505a0951a7817c8d53bbff45da0cbb2602d2979253926c937b"

echo
echo "===== 3. PRODUCTION PRECHECK ====="

for FILE in "$TMP_MIGRATION" "$TMP_CATEGORY" "$TMP_INDEX" "$TMP_CONTENT"
do
    test -f "$FILE"
done

ACTUAL_TMP_MIGRATION_HASH="$(sha256sum "$TMP_MIGRATION" | awk '{print $1}')"
ACTUAL_TMP_CATEGORY_HASH="$(sha256sum "$TMP_CATEGORY" | awk '{print $1}')"
ACTUAL_TMP_INDEX_HASH="$(sha256sum "$TMP_INDEX" | awk '{print $1}')"
ACTUAL_TMP_CONTENT_HASH="$(sha256sum "$TMP_CONTENT" | awk '{print $1}')"

CURRENT_CATEGORY_ACTUAL="$(sha256sum "$CATEGORY_DST" | awk '{print $1}')"
CURRENT_INDEX_ACTUAL="$(sha256sum "$INDEX_DST" | awk '{print $1}')"

echo "TMP_MIGRATION_SHA256=$ACTUAL_TMP_MIGRATION_HASH"
echo "TMP_CATEGORY_SHA256=$ACTUAL_TMP_CATEGORY_HASH"
echo "TMP_INDEX_SHA256=$ACTUAL_TMP_INDEX_HASH"
echo "TMP_CONTENT_SHA256=$ACTUAL_TMP_CONTENT_HASH"
echo "CURRENT_CATEGORY_SHA256=$CURRENT_CATEGORY_ACTUAL"
echo "CURRENT_INDEX_SHA256=$CURRENT_INDEX_ACTUAL"

test "$ACTUAL_TMP_MIGRATION_HASH" = "$EXPECTED_MIGRATION_HASH"
test "$ACTUAL_TMP_CATEGORY_HASH" = "$EXPECTED_CATEGORY_HASH"
test "$ACTUAL_TMP_INDEX_HASH" = "$EXPECTED_INDEX_HASH"
test "$ACTUAL_TMP_CONTENT_HASH" = "$EXPECTED_CONTENT_HASH"
test "$CURRENT_CATEGORY_ACTUAL" = "$CURRENT_CATEGORY_HASH"
test "$CURRENT_INDEX_ACTUAL" = "$CURRENT_INDEX_HASH"

test ! -e "$MIGRATION_DST"

php -l "$TMP_MIGRATION"
php -l "$TMP_CATEGORY"
php -l "$TMP_INDEX"

cd "$BACKEND"

php artisan tinker --execute='
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$total = \App\Models\Video::query()
    ->where("is_active", true)
    ->count();

$categories = \App\Models\Category::query()
    ->where("is_active", true)
    ->count();

$terms = DB::table("video_source_terms")->count();

$orphans = DB::table("video_source_terms as vst")
    ->leftJoin("videos as v", "v.id", "=", "vst.video_id")
    ->whereNull("v.id")
    ->count();

echo "TOTAL_ACTIVE=" . $total . PHP_EOL;
echo "ACTIVE_CATEGORIES=" . $categories . PHP_EOL;
echo "SOURCE_TERMS=" . $terms . PHP_EOL;
echo "ORPHANS=" . $orphans . PHP_EOL;
echo "HAS_DESCRIPTION=" . (Schema::hasColumn("categories", "description") ? "1" : "0") . PHP_EOL;
echo "HAS_META_DESCRIPTION=" . (Schema::hasColumn("categories", "meta_description") ? "1" : "0") . PHP_EOL;

if (
    $total !== 15455 ||
    $categories !== 25 ||
    $terms !== 223528 ||
    $orphans !== 0 ||
    ! Schema::hasColumn("categories", "description") ||
    Schema::hasColumn("categories", "meta_description")
) {
    throw new RuntimeException("Unexpected pre-deploy state.");
}

echo "PRODUCTION_BASELINE=PASS" . PHP_EOL;
'

echo "PRODUCTION_PRECHECK=PASS"

echo
echo "===== 4. BACKUP CURRENT STATE ====="

STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="$SEO_OPS/backups/category-seo-v1-$STAMP"

sudo mkdir -p "$BACKUP_DIR"
sudo cp "$CATEGORY_DST" "$BACKUP_DIR/Category.php"
sudo cp "$INDEX_DST" "$BACKUP_DIR/index.blade.php"

php artisan tinker --execute='
echo \App\Models\Category::query()
    ->orderBy("id")
    ->get(["id", "name", "slug", "description", "is_active", "updated_at"])
    ->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
' > /tmp/categories-seo-before.json

sudo cp /tmp/categories-seo-before.json "$BACKUP_DIR/categories-seo-before.json"

sudo chown -R --reference="$SEO_OPS" "$BACKUP_DIR" 2>/dev/null || true

echo "SEO_BACKUP_DIR=$BACKUP_DIR"
echo "SEO_BACKUP=PASS"

echo
echo "===== 5. INSTALL FILES ====="

sudo mkdir -p "$SEO_OPS"

MIGRATION_REFERENCE="$BACKEND/database/migrations/2026_07_10_102948_create_categories_table.php"

sudo cp "$TMP_MIGRATION" "$MIGRATION_DST"
sudo chown --reference="$MIGRATION_REFERENCE" "$MIGRATION_DST"
sudo chmod --reference="$MIGRATION_REFERENCE" "$MIGRATION_DST"

sudo cp "$TMP_CATEGORY" "$CATEGORY_DST"
sudo chown --reference="$BACKUP_DIR/Category.php" "$CATEGORY_DST"
sudo chmod --reference="$BACKUP_DIR/Category.php" "$CATEGORY_DST"

sudo cp "$TMP_INDEX" "$INDEX_DST"
sudo chown --reference="$BACKUP_DIR/index.blade.php" "$INDEX_DST"
sudo chmod --reference="$BACKUP_DIR/index.blade.php" "$INDEX_DST"

sudo cp "$TMP_CONTENT" "$CONTENT_DST"
sudo chown --reference="$CATEGORY_DST" "$CONTENT_DST"
sudo chmod 0644 "$CONTENT_DST"

php -l "$MIGRATION_DST"
php -l "$CATEGORY_DST"
php -l "$INDEX_DST"

INSTALLED_MIGRATION_HASH="$(sha256sum "$MIGRATION_DST" | awk '{print $1}')"
INSTALLED_CATEGORY_HASH="$(sha256sum "$CATEGORY_DST" | awk '{print $1}')"
INSTALLED_INDEX_HASH="$(sha256sum "$INDEX_DST" | awk '{print $1}')"
INSTALLED_CONTENT_HASH="$(sha256sum "$CONTENT_DST" | awk '{print $1}')"

echo "INSTALLED_MIGRATION_SHA256=$INSTALLED_MIGRATION_HASH"
echo "INSTALLED_CATEGORY_SHA256=$INSTALLED_CATEGORY_HASH"
echo "INSTALLED_INDEX_SHA256=$INSTALLED_INDEX_HASH"
echo "INSTALLED_CONTENT_SHA256=$INSTALLED_CONTENT_HASH"

test "$INSTALLED_MIGRATION_HASH" = "$EXPECTED_MIGRATION_HASH"
test "$INSTALLED_CATEGORY_HASH" = "$EXPECTED_CATEGORY_HASH"
test "$INSTALLED_INDEX_HASH" = "$EXPECTED_INDEX_HASH"
test "$INSTALLED_CONTENT_HASH" = "$EXPECTED_CONTENT_HASH"

echo "SEO_FILES_INSTALL=PASS"

echo
echo "===== 6. RUN META DESCRIPTION MIGRATION ====="

php artisan migrate \
    --force \
    --path="database/migrations/$MIGRATION_NAME"

php artisan tinker --execute='
use Illuminate\Support\Facades\Schema;

if (! Schema::hasColumn("categories", "meta_description")) {
    throw new RuntimeException("meta_description column was not created.");
}

echo "META_DESCRIPTION_COLUMN=PASS" . PHP_EOL;
'

echo
echo "===== 7. LOAD 25 CATEGORY SEO RECORDS ====="

php artisan tinker --execute='
use App\Models\Category;
use Illuminate\Support\Facades\DB;

$path = "/var/www/project-ares/ops/seo/category-seo-content-v1.json";

$data = json_decode(
    file_get_contents($path),
    true,
    512,
    JSON_THROW_ON_ERROR
);

if (count($data) !== 25) {
    throw new RuntimeException("SEO content must contain exactly 25 categories.");
}

$dataSlugs = collect(array_keys($data))
    ->sort()
    ->values()
    ->all();

$activeSlugs = Category::query()
    ->where("is_active", true)
    ->pluck("slug")
    ->sort()
    ->values()
    ->all();

if ($dataSlugs !== $activeSlugs) {
    throw new RuntimeException("SEO content slugs do not exactly match active categories.");
}

foreach ($data as $slug => $row) {
    $description = trim((string) ($row["description"] ?? ""));
    $meta = trim((string) ($row["meta_description"] ?? ""));

    if ($description === "" || $meta === "") {
        throw new RuntimeException("Blank SEO text for " . $slug);
    }

    if (mb_strlen($meta) > 255) {
        throw new RuntimeException("Meta description too long for " . $slug);
    }
}

if (
    count(array_unique(array_column($data, "description"))) !== 25 ||
    count(array_unique(array_column($data, "meta_description"))) !== 25
) {
    throw new RuntimeException("SEO descriptions must be unique across all 25 categories.");
}

DB::transaction(function () use ($data): void {
    foreach ($data as $slug => $row) {
        $category = Category::query()
            ->where("slug", $slug)
            ->where("is_active", true)
            ->lockForUpdate()
            ->firstOrFail();

        $category->description = trim($row["description"]);
        $category->meta_description = trim($row["meta_description"]);
        $category->save();
    }
});

echo "CATEGORY_SEO_WRITE=PASS" . PHP_EOL;
'

echo
echo "===== 8. EXACT CONTENT VALIDATION ====="

php artisan tinker --execute='
use App\Models\Category;

$data = json_decode(
    file_get_contents("/var/www/project-ares/ops/seo/category-seo-content-v1.json"),
    true,
    512,
    JSON_THROW_ON_ERROR
);

$descriptionValues = [];
$metaValues = [];

foreach ($data as $slug => $row) {
    $category = Category::query()
        ->where("slug", $slug)
        ->where("is_active", true)
        ->firstOrFail();

    if ($category->description !== $row["description"]) {
        throw new RuntimeException("Description mismatch: " . $slug);
    }

    if ($category->meta_description !== $row["meta_description"]) {
        throw new RuntimeException("Meta description mismatch: " . $slug);
    }

    $descriptionValues[] = $category->description;
    $metaValues[] = $category->meta_description;

    echo "SEO"
        . "|CATEGORY=" . $slug
        . "|DESCRIPTION_CHARS=" . mb_strlen($category->description)
        . "|META_CHARS=" . mb_strlen($category->meta_description)
        . PHP_EOL;
}

if (count(array_unique($descriptionValues)) !== 25) {
    throw new RuntimeException("Descriptions are not unique.");
}

if (count(array_unique($metaValues)) !== 25) {
    throw new RuntimeException("Meta descriptions are not unique.");
}

$japanese = Category::query()->where("slug", "japanese")->firstOrFail();
$asian = Category::query()->where("slug", "asian")->firstOrFail();
$mature = Category::query()->where("slug", "mature")->firstOrFail();
$milf = Category::query()->where("slug", "milf")->firstOrFail();

if (
    $japanese->description === $asian->description ||
    $japanese->meta_description === $asian->meta_description ||
    $mature->description === $milf->description ||
    $mature->meta_description === $milf->meta_description
) {
    throw new RuntimeException("Semantic pair separation validation failed.");
}

echo "SEO_CONTENT_EXACT_MATCH=PASS" . PHP_EOL;
echo "SEO_SEMANTIC_PAIR_SEPARATION=PASS" . PHP_EOL;
'

echo
echo "===== 9. APPLICATION VALIDATION ====="

php artisan optimize:clear

grep -q '\$activeCategory->meta_description' "$INDEX_DST"
grep -q "'meta_description'" "$CATEGORY_DST"

php artisan tinker --execute='
$japanese = \App\Models\Category::query()
    ->where("slug", "japanese")
    ->firstOrFail();

$pageDescription = $japanese->meta_description
    ?: $japanese->description
    ?: "Browse " . $japanese->name . " videos on Xurvexa.";

echo "JAPANESE_META=" . $pageDescription . PHP_EOL;

if ($pageDescription !== $japanese->meta_description) {
    throw new RuntimeException("Meta preference logic failed.");
}

echo "META_PREFERENCE_LOGIC=PASS" . PHP_EOL;
'

echo
echo "===== 10. SITEMAP CATEGORY CHECK ====="

SITEMAP="/tmp/xurvexa-category-seo-v1-sitemap.xml"
curl -fsS "https://xurvexa.com/sitemap.xml" -o "$SITEMAP"

CATEGORY_URL_COUNT="$(grep -o 'https://xurvexa.com/categories/[^<]*' "$SITEMAP" | wc -l | tr -d ' ')"

echo "SITEMAP_CATEGORY_URLS=$CATEGORY_URL_COUNT"
test "$CATEGORY_URL_COUNT" = "25"

for SLUG in \
    amateur milf asian latina anal pov blonde brunette big-tits hardcore \
    lesbian blowjob cumshot interracial handjob pornstar threesome mature \
    japanese ebony big-ass creampie bbw massage public
do
    grep -Fq "https://xurvexa.com/categories/$SLUG" "$SITEMAP"
done

rm -f "$SITEMAP"

echo "CATEGORY_SITEMAP=PASS"

echo
echo "===== 11. FINAL DATABASE INTEGRITY ====="

php artisan tinker --execute='
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$total = \App\Models\Video::query()
    ->where("is_active", true)
    ->count();

$categories = \App\Models\Category::query()
    ->where("is_active", true)
    ->count();

$withDescription = \App\Models\Category::query()
    ->where("is_active", true)
    ->whereNotNull("description")
    ->where("description", "!=", "")
    ->count();

$withMeta = \App\Models\Category::query()
    ->where("is_active", true)
    ->whereNotNull("meta_description")
    ->where("meta_description", "!=", "")
    ->count();

$terms = DB::table("video_source_terms")->count();

$orphans = DB::table("video_source_terms as vst")
    ->leftJoin("videos as v", "v.id", "=", "vst.video_id")
    ->whereNull("v.id")
    ->count();

echo "TOTAL_ACTIVE=" . $total . PHP_EOL;
echo "ACTIVE_CATEGORIES=" . $categories . PHP_EOL;
echo "CATEGORIES_WITH_DESCRIPTION=" . $withDescription . PHP_EOL;
echo "CATEGORIES_WITH_META_DESCRIPTION=" . $withMeta . PHP_EOL;
echo "SOURCE_TERMS=" . $terms . PHP_EOL;
echo "ORPHANS=" . $orphans . PHP_EOL;
echo "HAS_META_DESCRIPTION=" . (Schema::hasColumn("categories", "meta_description") ? "1" : "0") . PHP_EOL;

if (
    $total !== 15455 ||
    $categories !== 25 ||
    $withDescription !== 25 ||
    $withMeta !== 25 ||
    $terms !== 223528 ||
    $orphans !== 0 ||
    ! Schema::hasColumn("categories", "meta_description")
) {
    throw new RuntimeException("Final SEO integrity failed.");
}

echo "CATEGORY_SEO_FINAL_DB_INTEGRITY=PASS" . PHP_EOL;
'

echo
echo "CATEGORY_SEO_V1_DEPLOY=PASS"
'@

$RemoteScript = $RemoteScript.Replace("`r", '')

$RemoteScript | ssh `
    -o ServerAliveInterval=15 `
    -o ServerAliveCountMax=6 `
    -i $Key `
    $Target `
    'bash -s'

if ($LASTEXITCODE -ne 0) {
    throw 'Production deployment failed. Local project files were not changed.'
}

Write-Host ''
Write-Host '===== 12. SYNC VERIFIED FILES INTO LOCAL PROJECT ====='

$LocalMigration = Join-Path $Root "backend\database\migrations\$MigrationName"
$LocalCategory = Join-Path $Root 'backend\app\Models\Category.php'
$LocalIndex = Join-Path $Root 'backend\resources\views\videos\index.blade.php'
$LocalContent = Join-Path $Root 'ops\seo\category-seo-content-v1.json'

New-Item -ItemType Directory -Force -Path (Split-Path $LocalMigration) | Out-Null
New-Item -ItemType Directory -Force -Path (Split-Path $LocalCategory) | Out-Null
New-Item -ItemType Directory -Force -Path (Split-Path $LocalIndex) | Out-Null
New-Item -ItemType Directory -Force -Path (Split-Path $LocalContent) | Out-Null

Copy-Item -LiteralPath $StageMigration -Destination $LocalMigration -Force
Copy-Item -LiteralPath $StageCategory -Destination $LocalCategory -Force
Copy-Item -LiteralPath $StageIndex -Destination $LocalIndex -Force
Copy-Item -LiteralPath $Content -Destination $LocalContent -Force

Assert-FileHash -Path $LocalMigration -Expected $ExpectedMigrationHash
Assert-FileHash -Path $LocalCategory -Expected $ExpectedCategoryHash
Assert-FileHash -Path $LocalIndex -Expected $ExpectedIndexHash
Assert-FileHash -Path $LocalContent -Expected $ExpectedContentHash

Write-Host 'LOCAL_PROJECT_SYNC=PASS'
Write-Host ''
Write-Host 'CATEGORY_SEO_V1_ALL_COMPLETE=PASS'
