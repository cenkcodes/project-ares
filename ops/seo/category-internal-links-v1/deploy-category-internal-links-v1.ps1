$ErrorActionPreference = "Stop"

$Key = "C:\Users\Pc\.ssh\xurvexa_mojo_rsa"
$Target = "ubuntu@185.94.236.129"
$PackageRoot = "C:\Projects\Project-Ares\ops\seo\category-internal-links-v1"

$StageConfig = Join-Path $PackageRoot "stage\category-seo.php"
$StageIndex = Join-Path $PackageRoot "stage\index.blade.php"
$MapFile = Join-Path $PackageRoot "internal-link-map-v1.json"

$ExpectedConfigHash = "d01cdc3656e520b6d7c086b3e581929f3b3a0c6b8a3a4b5efe39c0d60a4a2bd7"
$ExpectedIndexHash = "254673747b72352bf5e8e47e0317b385ee1ae9284a74669df76e6a34f93b2f18"
$ExpectedMapHash = "69b8da0311b1b64f6d68a34e41772465b3833ffec03fc6850584b2cf5c1eaee7"
$ExpectedCurrentIndexHash = "e8cc3fedc34ad60efcec964aa89896f5792d767e085dc0c3135acd4ea1028336"

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

Assert-FileHash $StageConfig $ExpectedConfigHash
Assert-FileHash $StageIndex $ExpectedIndexHash
Assert-FileHash $MapFile $ExpectedMapHash

$MapData = Get-Content -Raw -LiteralPath $MapFile | ConvertFrom-Json
$CategoryProperties = @($MapData.mapping.PSObject.Properties)

if ($CategoryProperties.Count -ne 25) {
    throw "Internal-link map must contain exactly 25 categories."
}

foreach ($Property in $CategoryProperties) {
    $Links = @($Property.Value)

    if ($Links.Count -lt 3 -or $Links.Count -gt 4) {
        throw "Each category must have 3 or 4 related categories: $($Property.Name)"
    }

    if ($Links -contains $Property.Name) {
        throw "Self-link detected: $($Property.Name)"
    }

    if (@($Links | Sort-Object -Unique).Count -ne $Links.Count) {
        throw "Duplicate related category detected: $($Property.Name)"
    }
}

Write-Host "LOCAL_LINK_GRAPH=PASS"
Write-Host "LOCAL_PACKAGE_PRECHECK=PASS"

Write-Host ""
Write-Host "===== 2. UPLOAD STAGED FILES ====="

scp -i $Key $StageConfig "${Target}:/tmp/category-seo.php"
scp -i $Key $StageIndex "${Target}:/tmp/index.blade.php"
scp -i $Key $MapFile "${Target}:/tmp/internal-link-map-v1.json"

Write-Host "UPLOAD=PASS"

$Script = @'
set -euo pipefail

BACKEND="/var/www/project-ares/backend"
OPS="/var/www/project-ares/ops/seo"
CONFIG="$BACKEND/config/category-seo.php"
INDEX="$BACKEND/resources/views/videos/index.blade.php"
TMP_CONFIG="/tmp/category-seo.php"
TMP_INDEX="/tmp/index.blade.php"
TMP_MAP="/tmp/internal-link-map-v1.json"

EXPECTED_CONFIG_HASH="d01cdc3656e520b6d7c086b3e581929f3b3a0c6b8a3a4b5efe39c0d60a4a2bd7"
EXPECTED_INDEX_HASH="254673747b72352bf5e8e47e0317b385ee1ae9284a74669df76e6a34f93b2f18"
EXPECTED_MAP_HASH="69b8da0311b1b64f6d68a34e41772465b3833ffec03fc6850584b2cf5c1eaee7"
EXPECTED_OLD_INDEX_HASH="e8cc3fedc34ad60efcec964aa89896f5792d767e085dc0c3135acd4ea1028336"

STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="$OPS/backups/category-internal-links-v1-$STAMP"

echo
echo "===== 3. PRODUCTION PRECHECK ====="

test -f "$TMP_CONFIG"
test -f "$TMP_INDEX"
test -f "$TMP_MAP"

TMP_CONFIG_HASH="$(sha256sum "$TMP_CONFIG" | awk '{print $1}')"
TMP_INDEX_HASH="$(sha256sum "$TMP_INDEX" | awk '{print $1}')"
TMP_MAP_HASH="$(sha256sum "$TMP_MAP" | awk '{print $1}')"
CURRENT_INDEX_HASH="$(sha256sum "$INDEX" | awk '{print $1}')"

echo "TMP_CONFIG_SHA256=$TMP_CONFIG_HASH"
echo "TMP_INDEX_SHA256=$TMP_INDEX_HASH"
echo "TMP_MAP_SHA256=$TMP_MAP_HASH"
echo "CURRENT_INDEX_SHA256=$CURRENT_INDEX_HASH"

test "$TMP_CONFIG_HASH" = "$EXPECTED_CONFIG_HASH"
test "$TMP_INDEX_HASH" = "$EXPECTED_INDEX_HASH"
test "$TMP_MAP_HASH" = "$EXPECTED_MAP_HASH"
test "$CURRENT_INDEX_HASH" = "$EXPECTED_OLD_INDEX_HASH"

if [ -e "$CONFIG" ]; then
    echo "UNEXPECTED_EXISTING_CONFIG=$CONFIG"
    exit 1
fi

php -l "$TMP_CONFIG"
php -l "$TMP_INDEX"

cd "$BACKEND"

php artisan tinker --execute='
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$total =
    \App\Models\Video::query()
        ->where("is_active", true)
        ->count();

$categories =
    \App\Models\Category::query()
        ->where("is_active", true)
        ->count();

$descriptions =
    \App\Models\Category::query()
        ->where("is_active", true)
        ->whereNotNull("description")
        ->where("description", "!=", "")
        ->count();

$metaDescriptions =
    \App\Models\Category::query()
        ->where("is_active", true)
        ->whereNotNull("meta_description")
        ->where("meta_description", "!=", "")
        ->count();

$terms = DB::table("video_source_terms")->count();

$orphans =
    DB::table("video_source_terms as vst")
        ->leftJoin(
            "videos as v",
            "v.id",
            "=",
            "vst.video_id"
        )
        ->whereNull("v.id")
        ->count();

echo "TOTAL_ACTIVE=" . $total . PHP_EOL;
echo "ACTIVE_CATEGORIES=" . $categories . PHP_EOL;
echo "CATEGORIES_WITH_DESCRIPTION=" . $descriptions . PHP_EOL;
echo "CATEGORIES_WITH_META_DESCRIPTION=" . $metaDescriptions . PHP_EOL;
echo "SOURCE_TERMS=" . $terms . PHP_EOL;
echo "ORPHANS=" . $orphans . PHP_EOL;
echo "HAS_META_DESCRIPTION=" .
    (Schema::hasColumn("categories", "meta_description") ? "1" : "0") .
    PHP_EOL;

if (
    $total !== 15455 ||
    $categories !== 25 ||
    $descriptions !== 25 ||
    $metaDescriptions !== 25 ||
    $terms !== 223528 ||
    $orphans !== 0 ||
    !Schema::hasColumn("categories", "meta_description")
) {
    throw new RuntimeException(
        "Unexpected production baseline."
    );
}

echo "PRODUCTION_BASELINE=PASS" . PHP_EOL;
'

echo "PRODUCTION_PRECHECK=PASS"

echo
echo "===== 4. BACKUP CURRENT STATE ====="

mkdir -p "$BACKUP_DIR"
cp "$INDEX" "$BACKUP_DIR/index.blade.php"
cp "$TMP_MAP" "$BACKUP_DIR/internal-link-map-v1.json"

echo "INTERNAL_LINK_BACKUP_DIR=$BACKUP_DIR"
echo "INTERNAL_LINK_BACKUP=PASS"

echo
echo "===== 5. INSTALL CONFIG + COMPLETE VIEW ====="

sudo cp "$TMP_CONFIG" "$CONFIG"
sudo chown --reference="$BACKEND/config/app.php" "$CONFIG"
sudo chmod --reference="$BACKEND/config/app.php" "$CONFIG"

sudo cp "$TMP_INDEX" "$INDEX"
sudo chown --reference="$BACKUP_DIR/index.blade.php" "$INDEX"
sudo chmod --reference="$BACKUP_DIR/index.blade.php" "$INDEX"

php -l "$CONFIG"
php -l "$INDEX"

INSTALLED_CONFIG_HASH="$(sha256sum "$CONFIG" | awk '{print $1}')"
INSTALLED_INDEX_HASH="$(sha256sum "$INDEX" | awk '{print $1}')"

echo "INSTALLED_CONFIG_SHA256=$INSTALLED_CONFIG_HASH"
echo "INSTALLED_INDEX_SHA256=$INSTALLED_INDEX_HASH"

test "$INSTALLED_CONFIG_HASH" = "$EXPECTED_CONFIG_HASH"
test "$INSTALLED_INDEX_HASH" = "$EXPECTED_INDEX_HASH"

echo "INTERNAL_LINK_FILES_INSTALL=PASS"

echo
echo "===== 6. CLEAR CACHE ====="

php artisan optimize:clear

echo "CACHE_CLEAR=PASS"

echo
echo "===== 7. INTERNAL LINK GRAPH VALIDATION ====="

php artisan tinker --execute='
$map = config("category-seo.related_categories");

if (!is_array($map) || count($map) !== 25) {
    throw new RuntimeException(
        "Expected exactly 25 category mappings."
    );
}

$activeSlugs =
    \App\Models\Category::query()
        ->where("is_active", true)
        ->pluck("slug")
        ->all();

$activeLookup =
    array_fill_keys($activeSlugs, true);

$inbound =
    array_fill_keys($activeSlugs, 0);

$totalLinks = 0;

foreach ($map as $source => $targets) {
    if (!isset($activeLookup[$source])) {
        throw new RuntimeException(
            "Inactive/unknown source: " . $source
        );
    }

    if (
        !is_array($targets) ||
        count($targets) < 3 ||
        count($targets) > 4
    ) {
        throw new RuntimeException(
            "Invalid related count for " . $source
        );
    }

    if (count($targets) !== count(array_unique($targets))) {
        throw new RuntimeException(
            "Duplicate targets for " . $source
        );
    }

    foreach ($targets as $target) {
        if ($target === $source) {
            throw new RuntimeException(
                "Self link for " . $source
            );
        }

        if (!isset($activeLookup[$target])) {
            throw new RuntimeException(
                "Inactive/unknown target: " . $target
            );
        }

        $inbound[$target]++;
        $totalLinks++;
    }
}

foreach ($inbound as $slug => $count) {
    if ($count < 1) {
        throw new RuntimeException(
            "No contextual inbound link for " . $slug
        );
    }
}

echo "RELATED_CATEGORY_MAPS=" . count($map) . PHP_EOL;
echo "CONTEXTUAL_INTERNAL_LINKS=" . $totalLinks . PHP_EOL;
echo "MIN_CONTEXTUAL_INBOUND=" . min($inbound) . PHP_EOL;
echo "INTERNAL_LINK_GRAPH=PASS" . PHP_EOL;
'

echo
echo "===== 8. RENDER ALL 25 CATEGORY PAGES ====="

php artisan tinker --execute='
use App\Http\Controllers\VideoController;
use Illuminate\Http\Request;

$map = config("category-seo.related_categories");
$controller = app(VideoController::class);

foreach ($map as $slug => $targets) {
    $request =
        Request::create(
            "/categories/" . $slug,
            "GET"
        );

    $html =
        $controller
            ->category($request, $slug)
            ->render();

    if (!str_contains($html, "Related Categories")) {
        throw new RuntimeException(
            "Missing related section: " . $slug
        );
    }

    $renderedLinks =
        substr_count(
            $html,
            "class=\"related-category-link\""
        );

    if ($renderedLinks !== count($targets)) {
        throw new RuntimeException(
            "Unexpected rendered link count for " .
            $slug .
            ": " .
            $renderedLinks
        );
    }

    foreach ($targets as $target) {
        $url = route(
            "videos.category",
            $target
        );

        if (!str_contains($html, $url)) {
            throw new RuntimeException(
                "Missing target " .
                $target .
                " on " .
                $slug
            );
        }
    }

    echo
        "RENDER"
        . "|CATEGORY=" . $slug
        . "|LINKS=" . $renderedLinks
        . "|PASS"
        . PHP_EOL;
}

echo "ALL_CATEGORY_INTERNAL_LINK_RENDER=PASS" . PHP_EOL;
'

echo
echo "===== 9. SITEMAP REGRESSION ====="

SITEMAP="/tmp/xurvexa-category-internal-links-sitemap.xml"

curl -fsS     "https://xurvexa.com/sitemap.xml"     -o "$SITEMAP"

CATEGORY_URLS="$(grep -c '<loc>https://xurvexa.com/categories/' "$SITEMAP")"

echo "SITEMAP_CATEGORY_URLS=$CATEGORY_URLS"

test "$CATEGORY_URLS" -eq 25

rm -f "$SITEMAP"

echo "CATEGORY_SITEMAP_REGRESSION=PASS"

echo
echo "===== 10. FINAL DATABASE INTEGRITY ====="

php artisan tinker --execute='
use Illuminate\Support\Facades\DB;

$total =
    \App\Models\Video::query()
        ->where("is_active", true)
        ->count();

$categories =
    \App\Models\Category::query()
        ->where("is_active", true)
        ->count();

$descriptions =
    \App\Models\Category::query()
        ->where("is_active", true)
        ->whereNotNull("description")
        ->where("description", "!=", "")
        ->count();

$metaDescriptions =
    \App\Models\Category::query()
        ->where("is_active", true)
        ->whereNotNull("meta_description")
        ->where("meta_description", "!=", "")
        ->count();

$terms = DB::table("video_source_terms")->count();

$orphans =
    DB::table("video_source_terms as vst")
        ->leftJoin(
            "videos as v",
            "v.id",
            "=",
            "vst.video_id"
        )
        ->whereNull("v.id")
        ->count();

echo "TOTAL_ACTIVE=" . $total . PHP_EOL;
echo "ACTIVE_CATEGORIES=" . $categories . PHP_EOL;
echo "CATEGORIES_WITH_DESCRIPTION=" . $descriptions . PHP_EOL;
echo "CATEGORIES_WITH_META_DESCRIPTION=" . $metaDescriptions . PHP_EOL;
echo "SOURCE_TERMS=" . $terms . PHP_EOL;
echo "ORPHANS=" . $orphans . PHP_EOL;

if (
    $total !== 15455 ||
    $categories !== 25 ||
    $descriptions !== 25 ||
    $metaDescriptions !== 25 ||
    $terms !== 223528 ||
    $orphans !== 0
) {
    throw new RuntimeException(
        "Final database integrity failed."
    );
}

echo "CATEGORY_INTERNAL_LINK_FINAL_DB_INTEGRITY=PASS" . PHP_EOL;
'

echo
echo "CATEGORY_INTERNAL_LINKS_V1_DEPLOY=PASS"
'@

$Script = $Script.Replace("`r", "")

$Script | ssh `
    -o ServerAliveInterval=15 `
    -o ServerAliveCountMax=6 `
    -i $Key `
    $Target `
    "bash -s"

if ($LASTEXITCODE -ne 0) {
    throw "Production deploy failed. Local project files were NOT synced."
}

Write-Host ""
Write-Host "===== 11. SYNC VERIFIED FILES INTO LOCAL PROJECT ====="

$LocalConfig = "C:\Projects\Project-Ares\backend\config\category-seo.php"
$LocalIndex = "C:\Projects\Project-Ares\backend\resources\views\videos\index.blade.php"
$LocalMap = "C:\Projects\Project-Ares\ops\seo\internal-link-map-v1.json"

Copy-Item -Force -LiteralPath $StageConfig -Destination $LocalConfig
Copy-Item -Force -LiteralPath $StageIndex -Destination $LocalIndex
Copy-Item -Force -LiteralPath $MapFile -Destination $LocalMap

Assert-FileHash $LocalConfig $ExpectedConfigHash
Assert-FileHash $LocalIndex $ExpectedIndexHash
Assert-FileHash $LocalMap $ExpectedMapHash

Write-Host "LOCAL_PROJECT_SYNC=PASS"
Write-Host ""
Write-Host "CATEGORY_INTERNAL_LINKS_V1_ALL_COMPLETE=PASS"
