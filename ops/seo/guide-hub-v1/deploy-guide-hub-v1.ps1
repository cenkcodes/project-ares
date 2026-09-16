$ErrorActionPreference = "Stop"

$Key = "C:\Users\Pc\.ssh\xurvexa_mojo_rsa"
$Target = "ubuntu@185.94.236.129"
$PackageRoot = "C:\Projects\Project-Ares\ops\seo\guide-hub-v1"

$Payload = Join-Path $PackageRoot "payload.tar.gz"
$Manifest = Join-Path $PackageRoot "guide-hub-v1-manifest.json"

$ExpectedPayloadHash = "46913227b57eaeb520e43ed9aab61ec51c0ad2720ec2d838792550af16a8a298"
$ExpectedManifestHash = "499ef39a57f3cd16b58a113da030e6a8d9d98bb4565a8768ab6e08c00e8fe478"

$StageHashes = @{
    "stage/app/Http/Controllers/GuideController.php" = "644ee4035851d3f12d7be148c9132a96170fb9a3a84151b1684717b1f821f4e4"
    "stage/app/Http/Controllers/SeoController.php" = "5af22b43b8263ac291a4462701e742c00b039aae13c54e3e6e539be8c58ae38f"
    "stage/config/guide-seo.php" = "b3e39137222006aeff989d52f5d0d1604f814a9a9775efe68ff47225cfd8e498"
    "stage/resources/views/guides/index.blade.php" = "8bb9050e99e87433683e0122bec3991d9cd576389d0829e47c5fea9fda193c73"
    "stage/resources/views/guides/show.blade.php" = "2d5229c8bc4cd2b9ecba1ee2000b4ac6eaaf6fcbff93422cdee64c3a04527c9e"
    "stage/resources/views/seo/sitemap.blade.php" = "898d39c9d4ff0c3ba7f98a5fcd6e462dc6139371ada6837aeecbb804d4a7d135"
    "stage/resources/views/videos/index.blade.php" = "d3ea28bc4ea05cef797da09be30232dd2586c2b97c288bd2c4c6a9676576b58b"
    "stage/routes/web.php" = "858f275f9e9843894e2722aa4bf6d983d1a4455098bce5a40418a3e171dea5a4"
}

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

foreach ($RelativePath in $StageHashes.Keys | Sort-Object) {
    $LocalRelative = $RelativePath -replace '/', '\\'
    $FullPath = Join-Path $PackageRoot $LocalRelative
    Assert-FileHash $FullPath $StageHashes[$RelativePath]
}

$ManifestData = Get-Content -Raw -LiteralPath $Manifest | ConvertFrom-Json
if (@($ManifestData.pilot_guides).Count -ne 4) {
    throw "Guide manifest must contain exactly 4 pilot guides."
}

Write-Host "GUIDE_PACKAGE_PRECHECK=PASS"

Write-Host ""
Write-Host "===== 2. UPLOAD SINGLE PAYLOAD ====="

scp -i $Key $Payload "${Target}:/tmp/xurvexa-guide-hub-v1.tar.gz"
if ($LASTEXITCODE -ne 0) {
    throw "Payload upload failed."
}

Write-Host "UPLOAD=PASS"

$Script = @'
set -eEuo pipefail

BACKEND="/var/www/project-ares/backend"
OPS="/var/www/project-ares/ops/seo"
PAYLOAD="/tmp/xurvexa-guide-hub-v1.tar.gz"
TMP_ROOT="/tmp/xurvexa-guide-hub-v1"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="$OPS/backups/guide-hub-v1-$STAMP"

EXPECTED_PAYLOAD_HASH="46913227b57eaeb520e43ed9aab61ec51c0ad2720ec2d838792550af16a8a298"
EXPECTED_ROUTES_HASH="3c0e7b219487e303cb36d7cd7cbac974094410437c48619cf79a8d9e7514a96b"
EXPECTED_SEO_HASH="94b4ea2721d6972ed52346a448b605aa16b9b24caa39359f29742fa77f8c0c55"
EXPECTED_SITEMAP_HASH="5a0c9bb8b0dda93167846f89c96ab7dc73ad6fa039043bc730798114102e317a"
EXPECTED_INDEX_HASH="254673747b72352bf5e8e47e0317b385ee1ae9284a74669df76e6a34f93b2f18"
EXPECTED_CATEGORY_SEO_HASH="d01cdc3656e520b6d7c086b3e581929f3b3a0c6b8a3a4b5efe39c0d60a4a2bd7"
EXPECTED_LAYOUT_HASH="eed78c2f9fe11c80495b7b6b1f82fdcb6f2090b14b1466c90484f21d111c6a4e"

ROUTES="$BACKEND/routes/web.php"
SEO="$BACKEND/app/Http/Controllers/SeoController.php"
GUIDE_CONTROLLER="$BACKEND/app/Http/Controllers/GuideController.php"
GUIDE_CONFIG="$BACKEND/config/guide-seo.php"
SITEMAP="$BACKEND/resources/views/seo/sitemap.blade.php"
VIDEO_INDEX="$BACKEND/resources/views/videos/index.blade.php"
GUIDE_INDEX="$BACKEND/resources/views/guides/index.blade.php"
GUIDE_SHOW="$BACKEND/resources/views/guides/show.blade.php"
CATEGORY_SEO="$BACKEND/config/category-seo.php"
LAYOUT="$BACKEND/resources/views/layouts/public.blade.php"
MANIFEST_DEST="$OPS/guide-hub-v1-manifest.json"

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

    sudo cp -a "$BACKUP_DIR/web.php" "$ROUTES"
    sudo cp -a "$BACKUP_DIR/SeoController.php" "$SEO"
    sudo cp -a "$BACKUP_DIR/sitemap.blade.php" "$SITEMAP"
    sudo cp -a "$BACKUP_DIR/videos-index.blade.php" "$VIDEO_INDEX"

    sudo rm -f "$GUIDE_CONTROLLER"
    sudo rm -f "$GUIDE_CONFIG"
    sudo rm -f "$GUIDE_INDEX"
    sudo rm -f "$GUIDE_SHOW"
    sudo rmdir "$BACKEND/resources/views/guides" 2>/dev/null || true
    sudo rm -f "$MANIFEST_DEST"

    cd "$BACKEND"
    php artisan optimize:clear >/dev/null 2>&1 || true

    echo "GUIDE_HUB_V1_ROLLBACK=PASS"
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

test -f "$TMP_ROOT/guide-hub-v1-manifest.json"

python3 - "$TMP_ROOT" <<'PY'
import hashlib, json, pathlib, sys
root = pathlib.Path(sys.argv[1])
manifest = json.loads((root / "guide-hub-v1-manifest.json").read_text(encoding="utf-8"))
for item in manifest["stage_files"]:
    path = root / item["path"]
    if not path.is_file():
        raise SystemExit(f"Missing staged file: {item['path']}")
    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    print(f"TMP_SHA256|{item['path']}|{digest}")
    if digest != item["sha256"]:
        raise SystemExit(f"Staged hash mismatch: {item['path']}")
print("REMOTE_PAYLOAD_CONTENT=PASS")
PY

CURRENT_ROUTES_HASH="$(sha256sum "$ROUTES" | awk '{print $1}')"
CURRENT_SEO_HASH="$(sha256sum "$SEO" | awk '{print $1}')"
CURRENT_SITEMAP_HASH="$(sha256sum "$SITEMAP" | awk '{print $1}')"
CURRENT_INDEX_HASH="$(sha256sum "$VIDEO_INDEX" | awk '{print $1}')"
CURRENT_CATEGORY_SEO_HASH="$(sha256sum "$CATEGORY_SEO" | awk '{print $1}')"
CURRENT_LAYOUT_HASH="$(sha256sum "$LAYOUT" | awk '{print $1}')"

echo "CURRENT_ROUTES_SHA256=$CURRENT_ROUTES_HASH"
echo "CURRENT_SEO_SHA256=$CURRENT_SEO_HASH"
echo "CURRENT_SITEMAP_SHA256=$CURRENT_SITEMAP_HASH"
echo "CURRENT_VIDEO_INDEX_SHA256=$CURRENT_INDEX_HASH"
echo "CURRENT_CATEGORY_SEO_SHA256=$CURRENT_CATEGORY_SEO_HASH"
echo "CURRENT_LAYOUT_SHA256=$CURRENT_LAYOUT_HASH"

test "$CURRENT_ROUTES_HASH" = "$EXPECTED_ROUTES_HASH"
test "$CURRENT_SEO_HASH" = "$EXPECTED_SEO_HASH"
test "$CURRENT_SITEMAP_HASH" = "$EXPECTED_SITEMAP_HASH"
test "$CURRENT_INDEX_HASH" = "$EXPECTED_INDEX_HASH"
test "$CURRENT_CATEGORY_SEO_HASH" = "$EXPECTED_CATEGORY_SEO_HASH"
test "$CURRENT_LAYOUT_HASH" = "$EXPECTED_LAYOUT_HASH"

for NEW_FILE in "$GUIDE_CONTROLLER" "$GUIDE_CONFIG" "$GUIDE_INDEX" "$GUIDE_SHOW"; do
    if [ -e "$NEW_FILE" ]; then
        echo "UNEXPECTED_EXISTING_GUIDE_FILE=$NEW_FILE"
        exit 1
    fi
done

if [ -e "$MANIFEST_DEST" ]; then
    echo "UNEXPECTED_EXISTING_GUIDE_MANIFEST=$MANIFEST_DEST"
    exit 1
fi

php -l "$TMP_ROOT/stage/app/Http/Controllers/GuideController.php"
php -l "$TMP_ROOT/stage/app/Http/Controllers/SeoController.php"
php -l "$TMP_ROOT/stage/config/guide-seo.php"
php -l "$TMP_ROOT/stage/resources/views/guides/index.blade.php"
php -l "$TMP_ROOT/stage/resources/views/guides/show.blade.php"
php -l "$TMP_ROOT/stage/resources/views/seo/sitemap.blade.php"
php -l "$TMP_ROOT/stage/resources/views/videos/index.blade.php"
php -l "$TMP_ROOT/stage/routes/web.php"

cd "$BACKEND"

php artisan tinker --execute='
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$total = \App\Models\Video::query()->where("is_active", true)->count();
$categories = \App\Models\Category::query()->where("is_active", true)->count();
$descriptions = \App\Models\Category::query()->where("is_active", true)->whereNotNull("description")->where("description", "!=", "")->count();
$metaDescriptions = \App\Models\Category::query()->where("is_active", true)->whereNotNull("meta_description")->where("meta_description", "!=", "")->count();
$terms = DB::table("video_source_terms")->count();
$orphans = DB::table("video_source_terms as vst")->leftJoin("videos as v", "v.id", "=", "vst.video_id")->whereNull("v.id")->count();

echo "TOTAL_ACTIVE=" . $total . PHP_EOL;
echo "ACTIVE_CATEGORIES=" . $categories . PHP_EOL;
echo "CATEGORIES_WITH_DESCRIPTION=" . $descriptions . PHP_EOL;
echo "CATEGORIES_WITH_META_DESCRIPTION=" . $metaDescriptions . PHP_EOL;
echo "SOURCE_TERMS=" . $terms . PHP_EOL;
echo "ORPHANS=" . $orphans . PHP_EOL;
echo "HAS_META_DESCRIPTION=" . (Schema::hasColumn("categories", "meta_description") ? "1" : "0") . PHP_EOL;

if ($total !== 15455 || $categories !== 25 || $descriptions !== 25 || $metaDescriptions !== 25 || $terms !== 223528 || $orphans !== 0 || !Schema::hasColumn("categories", "meta_description")) {
    throw new RuntimeException("Unexpected production baseline.");
}

echo "PRODUCTION_BASELINE=PASS" . PHP_EOL;
'

echo "PRODUCTION_PRECHECK=PASS"

echo
echo "===== 3B. PRE-DEPLOY SITEMAP SNAPSHOT ====="

PRE_MAIN_SITEMAP="/tmp/xurvexa-guide-hub-pre-main-sitemap.xml"
PRE_VIDEO_SITEMAP="/tmp/xurvexa-guide-hub-pre-video-sitemap.xml"

curl -fsS "https://xurvexa.com/sitemap.xml?guidehubpre=$STAMP" -o "$PRE_MAIN_SITEMAP"
curl -fsS "https://xurvexa.com/video-sitemap.xml?guidehubpre=$STAMP" -o "$PRE_VIDEO_SITEMAP"

PRE_MAIN_TOTAL="$(grep -c '<url>' "$PRE_MAIN_SITEMAP")"
PRE_MAIN_CATEGORY_URLS="$(grep -c '<loc>https://xurvexa.com/categories/' "$PRE_MAIN_SITEMAP")"
PRE_MAIN_VIDEO_URLS="$(grep -c '<loc>https://xurvexa.com/videos/' "$PRE_MAIN_SITEMAP")"
PRE_MAIN_GUIDE_URLS="$(grep -c '<loc>https://xurvexa.com/guides' "$PRE_MAIN_SITEMAP" || true)"
PRE_VIDEO_SITEMAP_COUNT="$(grep -c '<video:video>' "$PRE_VIDEO_SITEMAP")"

echo "PRE_MAIN_TOTAL_URLS=$PRE_MAIN_TOTAL"
echo "PRE_MAIN_CATEGORY_URLS=$PRE_MAIN_CATEGORY_URLS"
echo "PRE_MAIN_VIDEO_URLS=$PRE_MAIN_VIDEO_URLS"
echo "PRE_MAIN_GUIDE_URLS=$PRE_MAIN_GUIDE_URLS"
echo "PRE_VIDEO_SITEMAP_ENTRIES=$PRE_VIDEO_SITEMAP_COUNT"

test "$PRE_MAIN_TOTAL" -eq 15482
test "$PRE_MAIN_CATEGORY_URLS" -eq 25
test "$PRE_MAIN_VIDEO_URLS" -eq 15455
test "$PRE_MAIN_GUIDE_URLS" -eq 0
test "$PRE_VIDEO_SITEMAP_COUNT" -gt 0

rm -f "$PRE_MAIN_SITEMAP" "$PRE_VIDEO_SITEMAP"

echo "PRE_DEPLOY_SITEMAP=PASS"

echo
echo "===== 4. BACKUP CURRENT STATE ====="

mkdir -p "$BACKUP_DIR"
sudo cp -a "$ROUTES" "$BACKUP_DIR/web.php"
sudo cp -a "$SEO" "$BACKUP_DIR/SeoController.php"
sudo cp -a "$SITEMAP" "$BACKUP_DIR/sitemap.blade.php"
sudo cp -a "$VIDEO_INDEX" "$BACKUP_DIR/videos-index.blade.php"

echo "GUIDE_HUB_BACKUP_DIR=$BACKUP_DIR"
echo "GUIDE_HUB_BACKUP=PASS"

echo
echo "===== 5. INSTALL COMPLETE FILES ====="

INSTALL_STARTED=1

sudo mkdir -p "$BACKEND/resources/views/guides"

sudo cp "$TMP_ROOT/stage/routes/web.php" "$ROUTES"
sudo chown --reference="$BACKUP_DIR/web.php" "$ROUTES"
sudo chmod --reference="$BACKUP_DIR/web.php" "$ROUTES"

sudo cp "$TMP_ROOT/stage/app/Http/Controllers/SeoController.php" "$SEO"
sudo chown --reference="$BACKUP_DIR/SeoController.php" "$SEO"
sudo chmod --reference="$BACKUP_DIR/SeoController.php" "$SEO"

sudo cp "$TMP_ROOT/stage/resources/views/seo/sitemap.blade.php" "$SITEMAP"
sudo chown --reference="$BACKUP_DIR/sitemap.blade.php" "$SITEMAP"
sudo chmod --reference="$BACKUP_DIR/sitemap.blade.php" "$SITEMAP"

sudo cp "$TMP_ROOT/stage/resources/views/videos/index.blade.php" "$VIDEO_INDEX"
sudo chown --reference="$BACKUP_DIR/videos-index.blade.php" "$VIDEO_INDEX"
sudo chmod --reference="$BACKUP_DIR/videos-index.blade.php" "$VIDEO_INDEX"

sudo cp "$TMP_ROOT/stage/app/Http/Controllers/GuideController.php" "$GUIDE_CONTROLLER"
sudo chown --reference="$SEO" "$GUIDE_CONTROLLER"
sudo chmod --reference="$SEO" "$GUIDE_CONTROLLER"

sudo cp "$TMP_ROOT/stage/config/guide-seo.php" "$GUIDE_CONFIG"
sudo chown --reference="$CATEGORY_SEO" "$GUIDE_CONFIG"
sudo chmod --reference="$CATEGORY_SEO" "$GUIDE_CONFIG"

sudo cp "$TMP_ROOT/stage/resources/views/guides/index.blade.php" "$GUIDE_INDEX"
sudo cp "$TMP_ROOT/stage/resources/views/guides/show.blade.php" "$GUIDE_SHOW"
sudo chown --reference="$BACKEND/resources/views/pages/about.blade.php" "$GUIDE_INDEX" "$GUIDE_SHOW"
sudo chmod --reference="$BACKEND/resources/views/pages/about.blade.php" "$GUIDE_INDEX" "$GUIDE_SHOW"

sudo cp "$TMP_ROOT/guide-hub-v1-manifest.json" "$MANIFEST_DEST"
sudo chown "$(id -u):$(id -g)" "$MANIFEST_DEST"
sudo chmod 644 "$MANIFEST_DEST"

php -l "$ROUTES"
php -l "$SEO"
php -l "$GUIDE_CONTROLLER"
php -l "$GUIDE_CONFIG"
php -l "$SITEMAP"
php -l "$VIDEO_INDEX"
php -l "$GUIDE_INDEX"
php -l "$GUIDE_SHOW"

echo "GUIDE_HUB_FILES_INSTALL=PASS"

echo
echo "===== 6. CLEAR CACHE ====="

php artisan optimize:clear

echo "CACHE_CLEAR=PASS"

echo
echo "===== 7. GUIDE CONTENT VALIDATION ====="

php artisan tinker --execute='
$guides = collect(config("guide-seo.guides", []))->filter(fn ($guide) => is_array($guide) && ($guide["is_active"] ?? false) === true);
$expectedSlugs = ["milf-vs-mature", "asian-vs-japanese", "bbw-vs-big-ass-vs-big-tits", "cumshot-vs-creampie"];

if ($guides->count() !== 4 || $guides->keys()->values()->all() !== $expectedSlugs) {
    throw new RuntimeException("Unexpected pilot guide set.");
}

$activeCategories = \App\Models\Category::query()->where("is_active", true)->pluck("slug")->all();
$activeLookup = array_fill_keys($activeCategories, true);

foreach ($guides as $slug => $guide) {
    $metaLength = strlen((string) ($guide["meta_description"] ?? ""));
    if ($metaLength < 100 || $metaLength > 160) {
        throw new RuntimeException("Meta length out of range: " . $slug . "=" . $metaLength);
    }

    $text = (string) ($guide["h1"] ?? "") . " " . (string) ($guide["intro"] ?? "");
    foreach (($guide["sections"] ?? []) as $section) {
        $text .= " " . (string) ($section["title"] ?? "");
        foreach (($section["paragraphs"] ?? []) as $paragraph) {
            $text .= " " . (string) $paragraph;
        }
    }
    $wordCount = preg_match_all("/[A-Za-z0-9-]+/u", $text, $matches);
    if ($wordCount < 600) {
        throw new RuntimeException("Guide too short: " . $slug . "=" . $wordCount);
    }

    foreach (($guide["category_links"] ?? []) as $link) {
        $categorySlug = (string) ($link["slug"] ?? "");
        if (!isset($activeLookup[$categorySlug])) {
            throw new RuntimeException("Unknown category link " . $categorySlug . " in " . $slug);
        }
    }

    echo "GUIDE|SLUG=" . $slug . "|WORDS=" . $wordCount . "|META=" . $metaLength . "|PASS" . PHP_EOL;
}

$categoryMap = config("guide-seo.category_guides", []);
foreach ($categoryMap as $categorySlug => $guideSlugs) {
    if (!isset($activeLookup[$categorySlug])) {
        throw new RuntimeException("Unknown mapped category: " . $categorySlug);
    }
    foreach ($guideSlugs as $guideSlug) {
        if (!$guides->has($guideSlug)) {
            throw new RuntimeException("Unknown mapped guide: " . $guideSlug);
        }
    }
}

echo "CATEGORY_GUIDE_MAPPINGS=" . count($categoryMap) . PHP_EOL;
echo "GUIDE_CONTENT_VALIDATION=PASS" . PHP_EOL;
'

echo
echo "===== 8. GUIDE ROUTE VALIDATION ====="

php artisan route:list --name=guides -v > /tmp/xurvexa-guide-routes.txt
cat /tmp/xurvexa-guide-routes.txt

grep -q "guides.index" /tmp/xurvexa-guide-routes.txt
grep -q "guides.show" /tmp/xurvexa-guide-routes.txt
grep -q "RequireAdultConsent" /tmp/xurvexa-guide-routes.txt

echo "GUIDE_ROUTES=PASS"

echo
echo "===== 9. RENDER GUIDE INDEX + ALL PILOT GUIDES ====="

php artisan tinker --execute='
$controller = app(\App\Http\Controllers\GuideController::class);

$indexHtml = $controller->index()->render();
if (!str_contains($indexHtml, "Xurvexa Category Guides") || !str_contains($indexHtml, route("guides.index")) || !str_contains($indexHtml, "index,follow")) {
    throw new RuntimeException("Guide index render validation failed.");
}

$guides = collect(config("guide-seo.guides", []))->filter(fn ($guide) => is_array($guide) && ($guide["is_active"] ?? false) === true);

foreach ($guides as $slug => $guide) {
    $html = $controller->show($slug)->render();
    $canonical = route("guides.show", ["slug" => $slug]);

    if (!str_contains($html, (string) $guide["h1"]) || !str_contains($html, $canonical) || !str_contains($html, "index,follow")) {
        throw new RuntimeException("Guide render basics failed: " . $slug);
    }

    foreach ($guide["category_links"] as $categoryLink) {
        $categoryUrl = route("videos.category", ["slug" => $categoryLink["slug"]]);
        if (!str_contains($html, $categoryUrl)) {
            throw new RuntimeException("Missing category link " . $categoryLink["slug"] . " on " . $slug);
        }
    }

    echo "GUIDE_RENDER|SLUG=" . $slug . "|PASS" . PHP_EOL;
}

echo "GUIDE_RENDER_ALL=PASS" . PHP_EOL;
'

echo
echo "===== 10. CATEGORY TO GUIDE LINK VALIDATION ====="

php artisan tinker --execute='
use App\Http\Controllers\VideoController;
use Illuminate\Http\Request;

$map = config("guide-seo.category_guides", []);
$controller = app(VideoController::class);

foreach ($map as $categorySlug => $guideSlugs) {
    $request = Request::create("/categories/" . $categorySlug, "GET");
    $html = $controller->category($request, $categorySlug)->render();

    if (!str_contains($html, "Xurvexa Guides") || !str_contains($html, route("guides.index"))) {
        throw new RuntimeException("Guide section missing on category " . $categorySlug);
    }

    $linkCount = substr_count($html, "category-guide-link");
    if ($linkCount !== count($guideSlugs)) {
        throw new RuntimeException("Guide link count mismatch on " . $categorySlug . ": " . $linkCount);
    }

    foreach ($guideSlugs as $guideSlug) {
        $url = route("guides.show", ["slug" => $guideSlug]);
        if (!str_contains($html, $url)) {
            throw new RuntimeException("Missing guide " . $guideSlug . " on category " . $categorySlug);
        }
    }

    echo "CATEGORY_GUIDE_RENDER|CATEGORY=" . $categorySlug . "|LINKS=" . $linkCount . "|PASS" . PHP_EOL;
}

echo "CATEGORY_GUIDE_LINK_RENDER=PASS" . PHP_EOL;
'

echo
echo "===== 11. MAIN + VIDEO SITEMAP VALIDATION ====="

MAIN_SITEMAP="/tmp/xurvexa-guide-hub-main-sitemap.xml"
VIDEO_SITEMAP="/tmp/xurvexa-guide-hub-video-sitemap.xml"

curl -fsS "https://xurvexa.com/sitemap.xml?guidehub=$STAMP" -o "$MAIN_SITEMAP"
curl -fsS "https://xurvexa.com/video-sitemap.xml?guidehub=$STAMP" -o "$VIDEO_SITEMAP"

TOTAL_URLS="$(grep -c '<url>' "$MAIN_SITEMAP")"
CATEGORY_URLS="$(grep -c '<loc>https://xurvexa.com/categories/' "$MAIN_SITEMAP")"
VIDEO_URLS="$(grep -c '<loc>https://xurvexa.com/videos/' "$MAIN_SITEMAP")"
GUIDE_URLS="$(grep -c '<loc>https://xurvexa.com/guides' "$MAIN_SITEMAP")"
VIDEO_SITEMAP_ENTRIES="$(grep -c '<video:video>' "$VIDEO_SITEMAP")"

echo "MAIN_SITEMAP_TOTAL_URLS=$TOTAL_URLS"
echo "MAIN_SITEMAP_CATEGORY_URLS=$CATEGORY_URLS"
echo "MAIN_SITEMAP_VIDEO_URLS=$VIDEO_URLS"
echo "MAIN_SITEMAP_GUIDE_URLS=$GUIDE_URLS"
echo "VIDEO_SITEMAP_ENTRIES=$VIDEO_SITEMAP_ENTRIES"

test "$TOTAL_URLS" -eq 15487
test "$CATEGORY_URLS" -eq 25
test "$VIDEO_URLS" -eq 15455
test "$GUIDE_URLS" -eq 5
test "$VIDEO_SITEMAP_ENTRIES" -eq "$PRE_VIDEO_SITEMAP_COUNT"

grep -q '<loc>https://xurvexa.com/guides</loc>' "$MAIN_SITEMAP"
grep -q '<loc>https://xurvexa.com/guides/milf-vs-mature</loc>' "$MAIN_SITEMAP"
grep -q '<loc>https://xurvexa.com/guides/asian-vs-japanese</loc>' "$MAIN_SITEMAP"
grep -q '<loc>https://xurvexa.com/guides/bbw-vs-big-ass-vs-big-tits</loc>' "$MAIN_SITEMAP"
grep -q '<loc>https://xurvexa.com/guides/cumshot-vs-creampie</loc>' "$MAIN_SITEMAP"

rm -f "$MAIN_SITEMAP" "$VIDEO_SITEMAP"

echo "GUIDE_SITEMAP=PASS"

echo
echo "===== 12. FINAL DATABASE INTEGRITY ====="

php artisan tinker --execute='
use Illuminate\Support\Facades\DB;

$total = \App\Models\Video::query()->where("is_active", true)->count();
$categories = \App\Models\Category::query()->where("is_active", true)->count();
$descriptions = \App\Models\Category::query()->where("is_active", true)->whereNotNull("description")->where("description", "!=", "")->count();
$metaDescriptions = \App\Models\Category::query()->where("is_active", true)->whereNotNull("meta_description")->where("meta_description", "!=", "")->count();
$terms = DB::table("video_source_terms")->count();
$orphans = DB::table("video_source_terms as vst")->leftJoin("videos as v", "v.id", "=", "vst.video_id")->whereNull("v.id")->count();

echo "TOTAL_ACTIVE=" . $total . PHP_EOL;
echo "ACTIVE_CATEGORIES=" . $categories . PHP_EOL;
echo "CATEGORIES_WITH_DESCRIPTION=" . $descriptions . PHP_EOL;
echo "CATEGORIES_WITH_META_DESCRIPTION=" . $metaDescriptions . PHP_EOL;
echo "SOURCE_TERMS=" . $terms . PHP_EOL;
echo "ORPHANS=" . $orphans . PHP_EOL;

if ($total !== 15455 || $categories !== 25 || $descriptions !== 25 || $metaDescriptions !== 25 || $terms !== 223528 || $orphans !== 0) {
    throw new RuntimeException("Guide Hub final DB integrity failed.");
}

echo "GUIDE_HUB_FINAL_DB_INTEGRITY=PASS" . PHP_EOL;
'

DEPLOY_COMMITTED=1

echo
echo "GUIDE_HUB_V1_DEPLOY=PASS"
'@

$Script = $Script.Replace("`r", "")

$Script | ssh `
    -o ServerAliveInterval=15 `
    -o ServerAliveCountMax=6 `
    -i $Key `
    $Target `
    "bash -s"

if ($LASTEXITCODE -ne 0) {
    throw "Production deploy failed. Rollback should have restored the pre-deploy state; inspect the printed rollback marker before any retry."
}

Write-Host ""
Write-Host "===== 13. SYNC VERIFIED FILES INTO LOCAL PROJECT ====="

$SyncMap = @{
    "stage\routes\web.php" = "C:\Projects\Project-Ares\backend\routes\web.php"
    "stage\app\Http\Controllers\GuideController.php" = "C:\Projects\Project-Ares\backend\app\Http\Controllers\GuideController.php"
    "stage\app\Http\Controllers\SeoController.php" = "C:\Projects\Project-Ares\backend\app\Http\Controllers\SeoController.php"
    "stage\config\guide-seo.php" = "C:\Projects\Project-Ares\backend\config\guide-seo.php"
    "stage\resources\views\guides\index.blade.php" = "C:\Projects\Project-Ares\backend\resources\views\guides\index.blade.php"
    "stage\resources\views\guides\show.blade.php" = "C:\Projects\Project-Ares\backend\resources\views\guides\show.blade.php"
    "stage\resources\views\seo\sitemap.blade.php" = "C:\Projects\Project-Ares\backend\resources\views\seo\sitemap.blade.php"
    "stage\resources\views\videos\index.blade.php" = "C:\Projects\Project-Ares\backend\resources\views\videos\index.blade.php"
}

foreach ($Entry in $SyncMap.GetEnumerator()) {
    $Source = Join-Path $PackageRoot $Entry.Key
    $Destination = $Entry.Value
    $DestinationDirectory = Split-Path -Parent $Destination
    New-Item -ItemType Directory -Force -Path $DestinationDirectory | Out-Null
    Copy-Item -Force -LiteralPath $Source -Destination $Destination

    $HashKey = ($Entry.Key -replace '\\', '/')
    Assert-FileHash $Destination $StageHashes[$HashKey]
}

$LocalManifest = "C:\Projects\Project-Ares\ops\seo\guide-hub-v1-manifest.json"
Copy-Item -Force -LiteralPath $Manifest -Destination $LocalManifest
Assert-FileHash $LocalManifest $ExpectedManifestHash

Write-Host "LOCAL_PROJECT_SYNC=PASS"
Write-Host ""
Write-Host "GUIDE_HUB_V1_ALL_COMPLETE=PASS"
