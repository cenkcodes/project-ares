$ErrorActionPreference = "Stop"

$Key = "C:\Users\Pc\.ssh\xurvexa_mojo_rsa"
$Target = "ubuntu@185.94.236.129"
$PackageRoot = "C:\Projects\Project-Ares\ops\seo\guide-hub-v2"

$Payload = Join-Path $PackageRoot "payload.tar.gz"
$Manifest = Join-Path $PackageRoot "guide-hub-v2-manifest.json"
$StageConfig = Join-Path $PackageRoot "stage\config\guide-seo.php"

$ExpectedPayloadHash = "118a2be93c753d7e8e066775b1a0649bb5b3233d72259e0093dc794e03143f4d"
$ExpectedManifestHash = "49970b9db18f703f24cc97c52bbb2b961d170a3a6112b1ee71c7ff7eb19e9223"
$ExpectedConfigHash = "fc2e9574b82db8ee881bd6043465e90185405432b551eccb0717fbdc88ecd91a"

function Assert-FileHash {
    param(
        [string]$Path,
        [string]$Expected
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        throw "Missing required file: $Path"
    }

    $Actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLowerInvariant()
    Write-Host "LOCAL_SHA256|$Path|$Actual"

    if ($Actual -ne $Expected) {
        throw "Unexpected SHA256 for $Path"
    }
}

Write-Host ""
Write-Host "===== 1. LOCAL PACKAGE PRECHECK ====="

Assert-FileHash $Payload $ExpectedPayloadHash
Assert-FileHash $Manifest $ExpectedManifestHash
Assert-FileHash $StageConfig $ExpectedConfigHash

$ManifestData = Get-Content -Raw -LiteralPath $Manifest | ConvertFrom-Json
if (@($ManifestData.new_guides).Count -ne 8) {
    throw "Guide Hub V2 manifest must contain exactly 8 new guides."
}
if (@($ManifestData.all_guides).Count -ne 12) {
    throw "Guide Hub V2 manifest must contain exactly 12 total guides."
}

Write-Host "GUIDE_V2_PACKAGE_PRECHECK=PASS"

Write-Host ""
Write-Host "===== 2. UPLOAD SINGLE PAYLOAD ====="

scp -i $Key $Payload "${Target}:/tmp/xurvexa-guide-hub-v2.tar.gz"
if ($LASTEXITCODE -ne 0) {
    throw "Guide Hub V2 payload upload failed."
}

Write-Host "UPLOAD=PASS"

$Script = @'
set -eEuo pipefail

BACKEND="/var/www/project-ares/backend"
OPS="/var/www/project-ares/ops/seo"
PAYLOAD="/tmp/xurvexa-guide-hub-v2.tar.gz"
TMP_ROOT="/tmp/xurvexa-guide-hub-v2"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="$OPS/backups/guide-hub-v2-$STAMP"

EXPECTED_PAYLOAD_HASH="118a2be93c753d7e8e066775b1a0649bb5b3233d72259e0093dc794e03143f4d"
EXPECTED_ROUTES_HASH="858f275f9e9843894e2722aa4bf6d983d1a4455098bce5a40418a3e171dea5a4"
EXPECTED_SEO_HASH="5af22b43b8263ac291a4462701e742c00b039aae13c54e3e6e539be8c58ae38f"
EXPECTED_SITEMAP_HASH="898d39c9d4ff0c3ba7f98a5fcd6e462dc6139371ada6837aeecbb804d4a7d135"
EXPECTED_VIDEO_INDEX_HASH="d3ea28bc4ea05cef797da09be30232dd2586c2b97c288bd2c4c6a9676576b58b"
EXPECTED_GUIDE_CONFIG_HASH="b3e39137222006aeff989d52f5d0d1604f814a9a9775efe68ff47225cfd8e498"
EXPECTED_GUIDE_CONTROLLER_HASH="644ee4035851d3f12d7be148c9132a96170fb9a3a84151b1684717b1f821f4e4"
EXPECTED_GUIDE_INDEX_HASH="8bb9050e99e87433683e0122bec3991d9cd576389d0829e47c5fea9fda193c73"
EXPECTED_GUIDE_SHOW_HASH="2d5229c8bc4cd2b9ecba1ee2000b4ac6eaaf6fcbff93422cdee64c3a04527c9e"
EXPECTED_CATEGORY_SEO_HASH="d01cdc3656e520b6d7c086b3e581929f3b3a0c6b8a3a4b5efe39c0d60a4a2bd7"
EXPECTED_LAYOUT_HASH="eed78c2f9fe11c80495b7b6b1f82fdcb6f2090b14b1466c90484f21d111c6a4e"

ROUTES="$BACKEND/routes/web.php"
SEO="$BACKEND/app/Http/Controllers/SeoController.php"
SITEMAP="$BACKEND/resources/views/seo/sitemap.blade.php"
VIDEO_INDEX="$BACKEND/resources/views/videos/index.blade.php"
GUIDE_CONFIG="$BACKEND/config/guide-seo.php"
GUIDE_CONTROLLER="$BACKEND/app/Http/Controllers/GuideController.php"
GUIDE_INDEX="$BACKEND/resources/views/guides/index.blade.php"
GUIDE_SHOW="$BACKEND/resources/views/guides/show.blade.php"
CATEGORY_SEO="$BACKEND/config/category-seo.php"
LAYOUT="$BACKEND/resources/views/layouts/public.blade.php"
V1_MANIFEST="$OPS/guide-hub-v1-manifest.json"
V2_MANIFEST="$OPS/guide-hub-v2-manifest.json"

INSTALL_STARTED=0
DEPLOY_COMMITTED=0

rollback() {
    local exit_code="$1"
    if [ "$DEPLOY_COMMITTED" -eq 1 ] || [ "$INSTALL_STARTED" -eq 0 ]; then
        return
    fi

    echo
    echo "===== ROLLBACK ====="
    echo "ROLLBACK_TRIGGERED=1"
    set +e
    sudo cp -a "$BACKUP_DIR/guide-seo.php" "$GUIDE_CONFIG"
    rm -f "$V2_MANIFEST"
    cd "$BACKEND"
    php artisan optimize:clear >/dev/null 2>&1 || true
    echo "GUIDE_HUB_V2_ROLLBACK=PASS"
    echo "ORIGINAL_EXIT_CODE=$exit_code"
}

on_exit() {
    local exit_code="$?"
    if [ "$exit_code" -ne 0 ]; then
        rollback "$exit_code"
    fi
}
trap on_exit EXIT

echo
echo "===== 3. PRODUCTION PRECHECK ====="

test -f "$PAYLOAD"
PAYLOAD_HASH="$(sha256sum "$PAYLOAD" | awk '{print $1}')"
echo "PAYLOAD_SHA256=$PAYLOAD_HASH"
test "$PAYLOAD_HASH" = "$EXPECTED_PAYLOAD_HASH"

rm -rf "$TMP_ROOT"
mkdir -p "$TMP_ROOT"
tar -xzf "$PAYLOAD" -C "$TMP_ROOT"

test -f "$TMP_ROOT/stage/config/guide-seo.php"
test -f "$TMP_ROOT/guide-hub-v2-manifest.json"
php -l "$TMP_ROOT/stage/config/guide-seo.php"

python3 - "$TMP_ROOT" <<'PY'
import hashlib, json, pathlib, sys
root = pathlib.Path(sys.argv[1])
manifest = json.loads((root / "guide-hub-v2-manifest.json").read_text(encoding="utf-8"))
for item in manifest["stage_files"]:
    path = root / item["path"]
    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    print(f"TMP_SHA256|{item['path']}|{digest}")
    if digest != item["sha256"]:
        raise SystemExit("Staged hash mismatch")
if len(manifest["new_guides"]) != 8 or len(manifest["all_guides"]) != 12:
    raise SystemExit("Unexpected manifest guide counts")
print("REMOTE_PAYLOAD_CONTENT=PASS")
PY

check_hash() {
    local label="$1"
    local file="$2"
    local expected="$3"
    local actual
    actual="$(sha256sum "$file" | awk '{print $1}')"
    echo "$label=$actual"
    test "$actual" = "$expected"
}

check_hash "CURRENT_ROUTES_SHA256" "$ROUTES" "$EXPECTED_ROUTES_HASH"
check_hash "CURRENT_SEO_SHA256" "$SEO" "$EXPECTED_SEO_HASH"
check_hash "CURRENT_SITEMAP_SHA256" "$SITEMAP" "$EXPECTED_SITEMAP_HASH"
check_hash "CURRENT_VIDEO_INDEX_SHA256" "$VIDEO_INDEX" "$EXPECTED_VIDEO_INDEX_HASH"
check_hash "CURRENT_GUIDE_CONFIG_SHA256" "$GUIDE_CONFIG" "$EXPECTED_GUIDE_CONFIG_HASH"
check_hash "CURRENT_GUIDE_CONTROLLER_SHA256" "$GUIDE_CONTROLLER" "$EXPECTED_GUIDE_CONTROLLER_HASH"
check_hash "CURRENT_GUIDE_INDEX_SHA256" "$GUIDE_INDEX" "$EXPECTED_GUIDE_INDEX_HASH"
check_hash "CURRENT_GUIDE_SHOW_SHA256" "$GUIDE_SHOW" "$EXPECTED_GUIDE_SHOW_HASH"
check_hash "CURRENT_CATEGORY_SEO_SHA256" "$CATEGORY_SEO" "$EXPECTED_CATEGORY_SEO_HASH"
check_hash "CURRENT_LAYOUT_SHA256" "$LAYOUT" "$EXPECTED_LAYOUT_HASH"

test -f "$V1_MANIFEST"
if [ -e "$V2_MANIFEST" ]; then
    echo "UNEXPECTED_EXISTING_V2_MANIFEST=$V2_MANIFEST"
    exit 1
fi

cd "$BACKEND"
php artisan tinker --execute='
use Illuminate\Support\Facades\DB;
$total = \App\Models\Video::query()->where("is_active", true)->count();
$categories = \App\Models\Category::query()->where("is_active", true)->count();
$descriptions = \App\Models\Category::query()->where("is_active", true)->whereNotNull("description")->where("description", "!=", "")->count();
$metaDescriptions = \App\Models\Category::query()->where("is_active", true)->whereNotNull("meta_description")->where("meta_description", "!=", "")->count();
$terms = DB::table("video_source_terms")->count();
$orphans = DB::table("video_source_terms as vst")->leftJoin("videos as v", "v.id", "=", "vst.video_id")->whereNull("v.id")->count();
$guides = collect(config("guide-seo.guides", []))->filter(fn ($g) => is_array($g) && ($g["is_active"] ?? false) === true);
echo "TOTAL_ACTIVE=".$total.PHP_EOL;
echo "ACTIVE_CATEGORIES=".$categories.PHP_EOL;
echo "CATEGORIES_WITH_DESCRIPTION=".$descriptions.PHP_EOL;
echo "CATEGORIES_WITH_META_DESCRIPTION=".$metaDescriptions.PHP_EOL;
echo "SOURCE_TERMS=".$terms.PHP_EOL;
echo "ORPHANS=".$orphans.PHP_EOL;
echo "CURRENT_ACTIVE_GUIDES=".$guides->count().PHP_EOL;
if ($total !== 15455 || $categories !== 25 || $descriptions !== 25 || $metaDescriptions !== 25 || $terms !== 223528 || $orphans !== 0 || $guides->count() !== 4) {
    throw new RuntimeException("Unexpected Guide Hub V1 production baseline.");
}
echo "PRODUCTION_BASELINE=PASS".PHP_EOL;
'

echo "PRODUCTION_PRECHECK=PASS"

echo
echo "===== 4. PRE-DEPLOY SITEMAP SNAPSHOT ====="

PRE_MAIN="/tmp/xurvexa-guide-v2-pre-main.xml"
PRE_VIDEO="/tmp/xurvexa-guide-v2-pre-video.xml"
curl -fsS "https://xurvexa.com/sitemap.xml?v2pre=$STAMP" -o "$PRE_MAIN"
curl -fsS "https://xurvexa.com/video-sitemap.xml?v2pre=$STAMP" -o "$PRE_VIDEO"
PRE_TOTAL="$(grep -c '<url>' "$PRE_MAIN")"
PRE_GUIDES="$(grep -c '<loc>https://xurvexa.com/guides' "$PRE_MAIN")"
PRE_VIDEO_ENTRIES="$(grep -c '<video:video>' "$PRE_VIDEO")"
echo "PRE_MAIN_TOTAL_URLS=$PRE_TOTAL"
echo "PRE_MAIN_GUIDE_URLS=$PRE_GUIDES"
echo "PRE_VIDEO_SITEMAP_ENTRIES=$PRE_VIDEO_ENTRIES"
test "$PRE_TOTAL" -eq 15487
test "$PRE_GUIDES" -eq 5
test "$PRE_VIDEO_ENTRIES" -eq 15455
rm -f "$PRE_MAIN" "$PRE_VIDEO"
echo "PRE_DEPLOY_SITEMAP=PASS"

echo
echo "===== 5. BACKUP CURRENT GUIDE CONFIG ====="

mkdir -p "$BACKUP_DIR"
sudo cp -a "$GUIDE_CONFIG" "$BACKUP_DIR/guide-seo.php"
echo "GUIDE_HUB_V2_BACKUP_DIR=$BACKUP_DIR"
echo "GUIDE_HUB_V2_BACKUP=PASS"

echo
echo "===== 6. INSTALL COMPLETE GUIDE CONFIG ====="

INSTALL_STARTED=1
sudo cp "$TMP_ROOT/stage/config/guide-seo.php" "$GUIDE_CONFIG"
sudo chown --reference="$BACKUP_DIR/guide-seo.php" "$GUIDE_CONFIG"
sudo chmod --reference="$BACKUP_DIR/guide-seo.php" "$GUIDE_CONFIG"
cp "$TMP_ROOT/guide-hub-v2-manifest.json" "$V2_MANIFEST"
php -l "$GUIDE_CONFIG"
echo "INSTALLED_GUIDE_CONFIG_SHA256=$(sha256sum "$GUIDE_CONFIG" | awk '{print $1}')"
echo "GUIDE_HUB_V2_CONFIG_INSTALL=PASS"

echo
echo "===== 7. CLEAR CACHE ====="
php artisan optimize:clear
echo "CACHE_CLEAR=PASS"

echo
echo "===== 8. PRESERVE V1 GUIDE DEFINITIONS ====="
php -r '
$old = include $argv[1];
$new = include $argv[2];
$slugs = ["milf-vs-mature","asian-vs-japanese","bbw-vs-big-ass-vs-big-tits","cumshot-vs-creampie"];
foreach ($slugs as $slug) {
    if (($old["guides"][$slug] ?? null) !== ($new["guides"][$slug] ?? null)) {
        fwrite(STDERR, "V1 guide changed: $slug\n"); exit(1);
    }
    echo "V1_GUIDE_UNCHANGED|SLUG=$slug|PASS\n";
}
echo "V1_GUIDE_DEFINITIONS_PRESERVED=PASS\n";
' "$BACKUP_DIR/guide-seo.php" "$GUIDE_CONFIG"

echo
echo "===== 9. VALIDATE ALL 12 GUIDE DEFINITIONS ====="
php artisan tinker --execute='
$guides = collect(config("guide-seo.guides", []))->filter(fn ($g) => is_array($g) && ($g["is_active"] ?? false) === true);
if ($guides->count() !== 12) { throw new RuntimeException("Expected 12 active guides."); }
$activeCategories = \App\Models\Category::query()->where("is_active", true)->pluck("slug")->flip()->all();
foreach ($guides as $slug => $guide) {
    $metaLength = mb_strlen((string)($guide["meta_description"] ?? ""));
    if ($metaLength < 115 || $metaLength > 155) { throw new RuntimeException("Meta length invalid: ".$slug."=".$metaLength); }
    $text = (string)($guide["h1"] ?? "")." ".(string)($guide["intro"] ?? "");
    foreach (($guide["sections"] ?? []) as $section) {
        $text .= " ".(string)($section["title"] ?? "");
        foreach (($section["paragraphs"] ?? []) as $paragraph) { $text .= " ".(string)$paragraph; }
    }
    $wordCount = preg_match_all("/[A-Za-z0-9’\x{2019}-]+/u", $text, $matches);
    if ($wordCount < 600) { throw new RuntimeException("Guide too short: ".$slug."=".$wordCount); }
    foreach (($guide["category_links"] ?? []) as $link) {
        $cat = (string)($link["slug"] ?? "");
        if (!isset($activeCategories[$cat])) { throw new RuntimeException("Unknown category link ".$cat." in ".$slug); }
    }
    foreach (($guide["related_guides"] ?? []) as $related) {
        if (!$guides->has($related)) { throw new RuntimeException("Unknown related guide ".$related." in ".$slug); }
        if ($related === $slug) { throw new RuntimeException("Self-related guide: ".$slug); }
    }
    echo "GUIDE|SLUG=".$slug."|WORDS=".$wordCount."|META=".$metaLength."|PASS".PHP_EOL;
}
$map = config("guide-seo.category_guides", []);
foreach ($map as $categorySlug => $guideSlugs) {
    if (!isset($activeCategories[$categorySlug])) { throw new RuntimeException("Unknown mapped category: ".$categorySlug); }
    if (count($guideSlugs) !== count(array_unique($guideSlugs))) { throw new RuntimeException("Duplicate mapped guide on ".$categorySlug); }
    foreach ($guideSlugs as $guideSlug) { if (!$guides->has($guideSlug)) { throw new RuntimeException("Unknown mapped guide: ".$guideSlug); } }
}
echo "ACTIVE_GUIDES=".$guides->count().PHP_EOL;
echo "CATEGORY_GUIDE_MAPPINGS=".count($map).PHP_EOL;
echo "GUIDE_V2_CONTENT_VALIDATION=PASS".PHP_EOL;
'

echo
echo "===== 10. RENDER GUIDE INDEX + ALL 12 GUIDES ====="
php artisan tinker --execute='
$controller = app(\App\Http\Controllers\GuideController::class);
$indexHtml = $controller->index()->render();
$guides = collect(config("guide-seo.guides", []))->filter(fn ($g) => is_array($g) && ($g["is_active"] ?? false) === true);
foreach ($guides as $slug => $guide) {
    $url = route("guides.show", ["slug" => $slug]);
    if (!str_contains($indexHtml, $url)) { throw new RuntimeException("Index missing guide: ".$slug); }
    $html = $controller->show($slug)->render();
    if (!str_contains($html, (string)$guide["h1"]) || !str_contains($html, $url) || !str_contains($html, "index,follow")) { throw new RuntimeException("Guide render failed: ".$slug); }
    foreach (($guide["category_links"] ?? []) as $link) {
        $catUrl = route("videos.category", ["slug" => $link["slug"]]);
        if (!str_contains($html, $catUrl)) { throw new RuntimeException("Category link missing: ".$slug." -> ".$link["slug"]); }
    }
    foreach (($guide["related_guides"] ?? []) as $related) {
        $relatedUrl = route("guides.show", ["slug" => $related]);
        if (!str_contains($html, $relatedUrl)) { throw new RuntimeException("Related guide link missing: ".$slug." -> ".$related); }
    }
    echo "GUIDE_RENDER|SLUG=".$slug."|PASS".PHP_EOL;
}
echo "GUIDE_V2_RENDER_ALL=PASS".PHP_EOL;
'

echo
echo "===== 11. CATEGORY TO GUIDE LINK REGRESSION ====="
php artisan tinker --execute='
use App\Http\Controllers\VideoController;
use Illuminate\Http\Request;
$map = config("guide-seo.category_guides", []);
$controller = app(VideoController::class);
foreach ($map as $categorySlug => $guideSlugs) {
    $request = Request::create("/categories/".$categorySlug, "GET");
    $html = $controller->category($request, $categorySlug)->render();
    $linkCount = substr_count($html, "category-guide-link");
    if ($linkCount !== count($guideSlugs)) { throw new RuntimeException("Guide link count mismatch: ".$categorySlug."=".$linkCount); }
    foreach ($guideSlugs as $guideSlug) {
        $url = route("guides.show", ["slug" => $guideSlug]);
        if (!str_contains($html, $url)) { throw new RuntimeException("Missing guide ".$guideSlug." on ".$categorySlug); }
    }
    echo "CATEGORY_GUIDE_RENDER|CATEGORY=".$categorySlug."|LINKS=".$linkCount."|PASS".PHP_EOL;
}
echo "CATEGORY_GUIDE_V2_LINK_RENDER=PASS".PHP_EOL;
'

echo
echo "===== 12. MAIN + VIDEO SITEMAP VALIDATION ====="
MAIN="/tmp/xurvexa-guide-v2-main.xml"
VIDEO="/tmp/xurvexa-guide-v2-video.xml"
curl -fsS "https://xurvexa.com/sitemap.xml?v2=$STAMP" -o "$MAIN"
curl -fsS "https://xurvexa.com/video-sitemap.xml?v2=$STAMP" -o "$VIDEO"
TOTAL="$(grep -c '<url>' "$MAIN")"
CATEGORIES="$(grep -c '<loc>https://xurvexa.com/categories/' "$MAIN")"
VIDEOS="$(grep -c '<loc>https://xurvexa.com/videos/' "$MAIN")"
GUIDES="$(grep -c '<loc>https://xurvexa.com/guides' "$MAIN")"
VIDEO_ENTRIES="$(grep -c '<video:video>' "$VIDEO")"
echo "MAIN_SITEMAP_TOTAL_URLS=$TOTAL"
echo "MAIN_SITEMAP_CATEGORY_URLS=$CATEGORIES"
echo "MAIN_SITEMAP_VIDEO_URLS=$VIDEOS"
echo "MAIN_SITEMAP_GUIDE_URLS=$GUIDES"
echo "VIDEO_SITEMAP_ENTRIES=$VIDEO_ENTRIES"
test "$TOTAL" -eq 15495
test "$CATEGORIES" -eq 25
test "$VIDEOS" -eq 15455
test "$GUIDES" -eq 13
test "$VIDEO_ENTRIES" -eq 15455
for slug in pov-videos-explained amateur-videos-explained blonde-vs-brunette japanese-video-categories mature-video-categories how-xurvexa-categories-work video-tags-and-categories find-videos-by-category; do
    grep -q "<loc>https://xurvexa.com/guides/$slug</loc>" "$MAIN"
done
rm -f "$MAIN" "$VIDEO"
echo "GUIDE_HUB_V2_SITEMAP=PASS"

echo
echo "===== 13. FINAL DATABASE INTEGRITY ====="
php artisan tinker --execute='
use Illuminate\Support\Facades\DB;
$total = \App\Models\Video::query()->where("is_active", true)->count();
$categories = \App\Models\Category::query()->where("is_active", true)->count();
$descriptions = \App\Models\Category::query()->where("is_active", true)->whereNotNull("description")->where("description", "!=", "")->count();
$metaDescriptions = \App\Models\Category::query()->where("is_active", true)->whereNotNull("meta_description")->where("meta_description", "!=", "")->count();
$terms = DB::table("video_source_terms")->count();
$orphans = DB::table("video_source_terms as vst")->leftJoin("videos as v", "v.id", "=", "vst.video_id")->whereNull("v.id")->count();
echo "TOTAL_ACTIVE=".$total.PHP_EOL;
echo "ACTIVE_CATEGORIES=".$categories.PHP_EOL;
echo "CATEGORIES_WITH_DESCRIPTION=".$descriptions.PHP_EOL;
echo "CATEGORIES_WITH_META_DESCRIPTION=".$metaDescriptions.PHP_EOL;
echo "SOURCE_TERMS=".$terms.PHP_EOL;
echo "ORPHANS=".$orphans.PHP_EOL;
if ($total !== 15455 || $categories !== 25 || $descriptions !== 25 || $metaDescriptions !== 25 || $terms !== 223528 || $orphans !== 0) { throw new RuntimeException("Guide Hub V2 DB integrity failed."); }
echo "GUIDE_HUB_V2_FINAL_DB_INTEGRITY=PASS".PHP_EOL;
'

DEPLOY_COMMITTED=1

echo
echo "GUIDE_HUB_V2_DEPLOY=PASS"
'@

$Script = $Script.Replace("`r", "")

$Script | ssh `
    -o ServerAliveInterval=15 `
    -o ServerAliveCountMax=6 `
    -i $Key `
    $Target `
    "bash -s"

if ($LASTEXITCODE -ne 0) {
    throw "Guide Hub V2 production deploy failed. Inspect rollback marker before any retry."
}

Write-Host ""
Write-Host "===== 14. SYNC VERIFIED FILE INTO LOCAL PROJECT ====="

$LocalConfig = "C:\Projects\Project-Ares\backend\config\guide-seo.php"
$LocalConfigDirectory = Split-Path -Parent $LocalConfig
New-Item -ItemType Directory -Force -Path $LocalConfigDirectory | Out-Null
Copy-Item -Force -LiteralPath $StageConfig -Destination $LocalConfig
Assert-FileHash $LocalConfig $ExpectedConfigHash

$LocalManifest = "C:\Projects\Project-Ares\ops\seo\guide-hub-v2-manifest.json"
Copy-Item -Force -LiteralPath $Manifest -Destination $LocalManifest
Assert-FileHash $LocalManifest $ExpectedManifestHash

Write-Host "LOCAL_PROJECT_SYNC=PASS"
Write-Host ""
Write-Host "GUIDE_HUB_V2_ALL_COMPLETE=PASS"
