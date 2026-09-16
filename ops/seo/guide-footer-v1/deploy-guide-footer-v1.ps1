$ErrorActionPreference = "Stop"

$Key = "C:\Users\Pc\.ssh\xurvexa_mojo_rsa"
$Target = "ubuntu@185.94.236.129"
$RemoteBackend = "/var/www/project-ares/backend"
$RemoteFooter = "$RemoteBackend/resources/views/partials/site-footer.blade.php"
$LocalFooter = "C:\Projects\Project-Ares\backend\resources\views\partials\site-footer.blade.php"

$ExpectedFooterSha = "07a67065fdbf7b94dfe1d3d74beb44df837e9126342170557411c1c6b74d9f78"
$ExpectedRoutesSha = "858f275f9e9843894e2722aa4bf6d983d1a4455098bce5a40418a3e171dea5a4"
$ExpectedGuideConfigSha = "fc2e9574b82db8ee881bd6043465e90185405432b551eccb0717fbdc88ecd91a"

$RemoteScript = @'
set -euo pipefail

BACKEND="/var/www/project-ares/backend"
FOOTER="$BACKEND/resources/views/partials/site-footer.blade.php"
EXPECTED_FOOTER_SHA="07a67065fdbf7b94dfe1d3d74beb44df837e9126342170557411c1c6b74d9f78"
EXPECTED_ROUTES_SHA="858f275f9e9843894e2722aa4bf6d983d1a4455098bce5a40418a3e171dea5a4"
EXPECTED_GUIDE_CONFIG_SHA="fc2e9574b82db8ee881bd6043465e90185405432b551eccb0717fbdc88ecd91a"

cd "$BACKEND"

echo
echo "===== 1. PRODUCTION PRECHECK ====="

CURRENT_FOOTER_SHA="$(sha256sum "$FOOTER" | awk '{print $1}')"
CURRENT_ROUTES_SHA="$(sha256sum routes/web.php | awk '{print $1}')"
CURRENT_GUIDE_CONFIG_SHA="$(sha256sum config/guide-seo.php | awk '{print $1}')"

echo "CURRENT_FOOTER_SHA256=$CURRENT_FOOTER_SHA"
echo "CURRENT_ROUTES_SHA256=$CURRENT_ROUTES_SHA"
echo "CURRENT_GUIDE_CONFIG_SHA256=$CURRENT_GUIDE_CONFIG_SHA"

test "$CURRENT_FOOTER_SHA" = "$EXPECTED_FOOTER_SHA"
test "$CURRENT_ROUTES_SHA" = "$EXPECTED_ROUTES_SHA"
test "$CURRENT_GUIDE_CONFIG_SHA" = "$EXPECTED_GUIDE_CONFIG_SHA"

php artisan tinker --execute='
use App\Models\Category;
use App\Models\Video;
use Illuminate\Support\Facades\DB;

echo "TOTAL_ACTIVE=" . Video::query()->where("is_active", true)->count() . PHP_EOL;
echo "ACTIVE_CATEGORIES=" . Category::query()->where("is_active", true)->count() . PHP_EOL;
echo "SOURCE_TERMS=" . DB::table("video_source_terms")->count() . PHP_EOL;
echo "ORPHANS=" . DB::table("video_source_terms")
    ->leftJoin("videos", "videos.id", "=", "video_source_terms.video_id")
    ->whereNull("videos.id")
    ->count() . PHP_EOL;

$guides = collect(config("guide-seo.guides", []))
    ->filter(fn ($guide) => (bool) ($guide["is_active"] ?? false));

echo "ACTIVE_GUIDES=" . $guides->count() . PHP_EOL;

if (
    Video::query()->where("is_active", true)->count() !== 15455
    || Category::query()->where("is_active", true)->count() !== 25
    || DB::table("video_source_terms")->count() !== 223528
    || $guides->count() !== 12
) {
    throw new RuntimeException("Production baseline mismatch.");
}
'

echo "PRODUCTION_PRECHECK=PASS"

echo
echo "===== 2. BACKUP CURRENT FOOTER ====="

STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="/var/www/project-ares/ops/seo/backups/guide-footer-v1-$STAMP"
mkdir -p "$BACKUP_DIR"
cp -a "$FOOTER" "$BACKUP_DIR/site-footer.blade.php"

echo "GUIDE_FOOTER_BACKUP_DIR=$BACKUP_DIR"
echo "GUIDE_FOOTER_BACKUP=PASS"

rollback() {
    code=$?
    if [ "$code" -ne 0 ]; then
        echo
        echo "===== ROLLBACK ====="
        cp -a "$BACKUP_DIR/site-footer.blade.php" "$FOOTER"
        php artisan optimize:clear >/dev/null 2>&1 || true
        echo "GUIDE_FOOTER_ROLLBACK=PASS"
        echo "ORIGINAL_EXIT_CODE=$code"
    fi
}
trap rollback EXIT

echo
echo "===== 3. INSERT GUIDES FOOTER LINK ====="

python3 - <<'PY'
from pathlib import Path

path = Path("/var/www/project-ares/backend/resources/views/partials/site-footer.blade.php")
text = path.read_text(encoding="utf-8")

if "route('guides.index')" in text:
    raise SystemExit("Guides footer link already exists unexpectedly.")

needle = """                <a href="{{ route('pages.about') }}">
                    About
                </a>
"""

replacement = needle + """
                <a href="{{ route('guides.index') }}">
                    Guides
                </a>
"""

if text.count(needle) != 1:
    raise SystemExit("Expected About footer block was not found exactly once.")

updated = text.replace(needle, replacement, 1)
path.write_text(updated, encoding="utf-8", newline="")
PY

php -l "$FOOTER"

NEW_FOOTER_SHA="$(sha256sum "$FOOTER" | awk '{print $1}')"
echo "NEW_FOOTER_SHA256=$NEW_FOOTER_SHA"

test "$NEW_FOOTER_SHA" != "$EXPECTED_FOOTER_SHA"

echo "GUIDE_FOOTER_FILE_INSTALL=PASS"

echo
echo "===== 4. CLEAR CACHE ====="

php artisan optimize:clear
echo "CACHE_CLEAR=PASS"

echo
echo "===== 5. ROUTE + RENDER VALIDATION ====="

php artisan route:list --name=guides.index

php artisan tinker --execute='
$html = view("partials.site-footer")->render();

if (! str_contains($html, route("guides.index"))) {
    throw new RuntimeException("Rendered footer does not contain Guides URL.");
}

if (substr_count($html, route("guides.index")) !== 1) {
    throw new RuntimeException("Rendered footer contains duplicate Guides links.");
}

echo "GUIDES_FOOTER_RENDER=PASS" . PHP_EOL;
'

echo "GUIDE_FOOTER_ROUTE_RENDER=PASS"

echo
echo "===== 6. SITEMAP REGRESSION ====="

MAIN_XML="$(curl -fsSL https://xurvexa.com/sitemap.xml)"
VIDEO_XML="$(curl -fsSL https://xurvexa.com/video-sitemap.xml)"

MAIN_TOTAL="$(printf '%s' "$MAIN_XML" | grep -o '<url>' | wc -l | tr -d ' ')"
MAIN_GUIDES="$(printf '%s' "$MAIN_XML" | grep -o 'https://xurvexa.com/guides[^<]*' | wc -l | tr -d ' ')"
VIDEO_TOTAL="$(printf '%s' "$VIDEO_XML" | grep -o '<video:video>' | wc -l | tr -d ' ')"

echo "MAIN_SITEMAP_TOTAL_URLS=$MAIN_TOTAL"
echo "MAIN_SITEMAP_GUIDE_URLS=$MAIN_GUIDES"
echo "VIDEO_SITEMAP_ENTRIES=$VIDEO_TOTAL"

test "$MAIN_TOTAL" -eq 15495
test "$MAIN_GUIDES" -eq 13
test "$VIDEO_TOTAL" -eq 15455

echo "GUIDE_FOOTER_SITEMAP_REGRESSION=PASS"

echo
echo "===== 7. FINAL DATABASE INTEGRITY ====="

php artisan tinker --execute='
use App\Models\Category;
use App\Models\Video;
use Illuminate\Support\Facades\DB;

$total = Video::query()->where("is_active", true)->count();
$categories = Category::query()->where("is_active", true)->count();
$sourceTerms = DB::table("video_source_terms")->count();
$orphans = DB::table("video_source_terms")
    ->leftJoin("videos", "videos.id", "=", "video_source_terms.video_id")
    ->whereNull("videos.id")
    ->count();

echo "TOTAL_ACTIVE={$total}" . PHP_EOL;
echo "ACTIVE_CATEGORIES={$categories}" . PHP_EOL;
echo "SOURCE_TERMS={$sourceTerms}" . PHP_EOL;
echo "ORPHANS={$orphans}" . PHP_EOL;

if (
    $total !== 15455
    || $categories !== 25
    || $sourceTerms !== 223528
    || $orphans !== 0
) {
    throw new RuntimeException("Final database integrity mismatch.");
}
'

echo "GUIDE_FOOTER_FINAL_DB_INTEGRITY=PASS"

trap - EXIT

echo
echo "GUIDE_FOOTER_V1_DEPLOY=PASS"
'@

$RemoteScript = $RemoteScript.Replace("`r", "")

$RemoteScript | ssh `
    -o ServerAliveInterval=15 `
    -o ServerAliveCountMax=6 `
    -i $Key `
    $Target `
    "bash -s"

if ($LASTEXITCODE -ne 0) {
    throw "Guide Footer V1 production deploy failed."
}

Write-Host ""
Write-Host "===== 8. SYNC VERIFIED FOOTER INTO LOCAL PROJECT ====="

$LocalDir = Split-Path -Parent $LocalFooter
New-Item -ItemType Directory -Force -Path $LocalDir | Out-Null

scp `
    -i $Key `
    "${Target}:${RemoteFooter}" `
    $LocalFooter

if ($LASTEXITCODE -ne 0) {
    throw "Production deploy passed, but local footer sync failed."
}

$LocalSha = (
    Get-FileHash `
        -Algorithm SHA256 `
        -Path $LocalFooter
).Hash.ToLowerInvariant()

Write-Host "LOCAL_FOOTER_SHA256=$LocalSha"

if ($LocalSha -eq $ExpectedFooterSha) {
    throw "Local footer hash did not change after deployment."
}

Write-Host "LOCAL_PROJECT_SYNC=PASS"
Write-Host ""
Write-Host "GUIDE_FOOTER_V1_ALL_COMPLETE=PASS"
