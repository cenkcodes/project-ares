$ErrorActionPreference = "Stop"

$KeyFile = "C:\Users\Pc\.ssh\xurvexa_mojo_rsa"
$Server = "ubuntu@185.94.236.129"

$SelectorLocal = "C:\Projects\Project-Ares\backend\app\Services\Monetization\AdPlacementSelector.php"
$RendererLocal = "C:\Projects\Project-Ares\backend\resources\js\video-banner-renderer.js"
$DriverLocal = "C:\Projects\Project-Ares\backend\resources\js\video-adsterra-banner-driver.js"

$SelectorExpected = "3f2c3eb42b74eff2903a4ea26b06ae09ace40c5bcb0c231282f6e06af5f7ec9f"
$RendererExpected = "c5bef321565e27fdcb151f45d4317f84e9ff6f1702ec038ca2a21a7cf0db9756"
$DriverExpected = "fda3a352cb4122d6a6129c1c1a2f0b27014f3acf5192d2a33dedabdbedebe084"

$SelectorRemote = "/var/www/project-ares/backend/app/Services/Monetization/AdPlacementSelector.php"
$RendererRemote = "/var/www/project-ares/backend/resources/js/video-banner-renderer.js"
$DriverRemote = "/var/www/project-ares/backend/resources/js/video-adsterra-banner-driver.js"

Write-Host ""
Write-Host "===== LOCAL ADSTERRA FILE CHECK ====="

$SelectorActual = (Get-FileHash $SelectorLocal -Algorithm SHA256).Hash.ToLower()
$RendererActual = (Get-FileHash $RendererLocal -Algorithm SHA256).Hash.ToLower()
$DriverActual = (Get-FileHash $DriverLocal -Algorithm SHA256).Hash.ToLower()

Write-Host "SELECTOR_SHA256=$SelectorActual"
Write-Host "RENDERER_SHA256=$RendererActual"
Write-Host "DRIVER_SHA256=$DriverActual"

if ($SelectorActual -ne $SelectorExpected) {
    throw "AdPlacementSelector.php HASH MISMATCH"
}

if ($RendererActual -ne $RendererExpected) {
    throw "video-banner-renderer.js HASH MISMATCH"
}

if ($DriverActual -ne $DriverExpected) {
    throw "video-adsterra-banner-driver.js HASH MISMATCH"
}

Write-Host "LOCAL_ADSTERRA_FILES=PASS"

Write-Host ""
Write-Host "===== PRODUCTION BACKUP ====="

$BackupScript = @'
set -euo pipefail

cp /var/www/project-ares/backend/app/Services/Monetization/AdPlacementSelector.php \
   /var/www/project-ares/backend/app/Services/Monetization/AdPlacementSelector.php.bak-20260831-adsterra-80-20

cp /var/www/project-ares/backend/resources/js/video-banner-renderer.js \
   /var/www/project-ares/backend/resources/js/video-banner-renderer.js.bak-20260831-adsterra-80-20

if [ -f /var/www/project-ares/backend/resources/js/video-adsterra-banner-driver.js ]; then
    cp /var/www/project-ares/backend/resources/js/video-adsterra-banner-driver.js \
       /var/www/project-ares/backend/resources/js/video-adsterra-banner-driver.js.bak-20260831-adsterra-80-20
fi

echo "ADSTERRA_BACKUP=PASS"
'@

$BackupScript | ssh -i $KeyFile $Server 'bash -s'

Write-Host ""
Write-Host "===== UPLOAD ADSTERRA FILES ====="

scp -i $KeyFile $SelectorLocal "${Server}:${SelectorRemote}"
scp -i $KeyFile $RendererLocal "${Server}:${RendererRemote}"
scp -i $KeyFile $DriverLocal "${Server}:${DriverRemote}"

Write-Host ""
Write-Host "===== PRODUCTION CODE VALIDATION / VITE BUILD ====="

$CodeValidation = @'
set -euo pipefail

cd /var/www/project-ares/backend

php -l app/Services/Monetization/AdPlacementSelector.php

SELECTOR_ACTUAL="$(sha256sum app/Services/Monetization/AdPlacementSelector.php | awk '{print $1}')"
RENDERER_ACTUAL="$(sha256sum resources/js/video-banner-renderer.js | awk '{print $1}')"
DRIVER_ACTUAL="$(sha256sum resources/js/video-adsterra-banner-driver.js | awk '{print $1}')"

SELECTOR_EXPECTED="3f2c3eb42b74eff2903a4ea26b06ae09ace40c5bcb0c231282f6e06af5f7ec9f"
RENDERER_EXPECTED="c5bef321565e27fdcb151f45d4317f84e9ff6f1702ec038ca2a21a7cf0db9756"
DRIVER_EXPECTED="fda3a352cb4122d6a6129c1c1a2f0b27014f3acf5192d2a33dedabdbedebe084"

echo "PRODUCTION_SELECTOR_SHA256=$SELECTOR_ACTUAL"
echo "PRODUCTION_RENDERER_SHA256=$RENDERER_ACTUAL"
echo "PRODUCTION_DRIVER_SHA256=$DRIVER_ACTUAL"

test "$SELECTOR_ACTUAL" = "$SELECTOR_EXPECTED"
test "$RENDERER_ACTUAL" = "$RENDERER_EXPECTED"
test "$DRIVER_ACTUAL" = "$DRIVER_EXPECTED"

grep -q "traffic_weight" app/Services/Monetization/AdPlacementSelector.php
grep -q "video-adsterra-banner-driver" resources/js/video-banner-renderer.js
grep -q "ADSTERRA_DRIVER_NAME" resources/js/video-adsterra-banner-driver.js

npm run build

echo "ADSTERRA_CODE_BUILD=PASS"
'@

$CodeValidation | ssh -i $KeyFile $Server 'bash -s'

Write-Host ""
Write-Host "===== CONFIGURE EXOCLICK 80 / ADSTERRA 20 ====="

$DatabaseScript = @'
set -euo pipefail

cd /var/www/project-ares/backend

php artisan tinker --execute='
use App\Models\AdNetwork;
use App\Models\AdPlacement;
use Illuminate\Support\Facades\DB;

DB::transaction(function (): void {
    $exoClick = AdNetwork::query()
        ->where("slug", "exoclick")
        ->firstOrFail();

    $exoPlacement = AdPlacement::query()
        ->where("ad_network_id", $exoClick->id)
        ->where("placement_key", "video_banner")
        ->firstOrFail();

    $exoConfig = $exoPlacement->public_config ?? [];

    if (! is_array($exoConfig)) {
        $exoConfig = [];
    }

    $exoConfig["width"] = 300;
    $exoConfig["height"] = 250;
    $exoConfig["traffic_weight"] = 80;

    $exoPlacement->update([
        "format" => "banner",
        "is_active" => true,
        "priority" => 10,
        "desktop_enabled" => true,
        "mobile_enabled" => true,
        "public_config" => $exoConfig,
    ]);

    $adsterra = AdNetwork::query()
        ->updateOrCreate(
            [
                "slug" => "adsterra",
            ],
            [
                "name" => "Adsterra",
                "driver" => "adsterra",
                "is_active" => true,
                "priority" => 20,
                "supports_native" => false,
                "supports_banner" => true,
                "supports_preroll" => false,
                "supports_midroll" => false,
                "supports_popunder" => false,
                "supports_interstitial" => false,
                "notes" => "Adsterra approved production network. Initial launch restricted to 300x250 video banner at 20% traffic weight.",
            ]
        );

    AdPlacement::query()
        ->updateOrCreate(
            [
                "ad_network_id" => $adsterra->id,
                "placement_key" => "video_banner",
            ],
            [
                "format" => "banner",
                "is_active" => true,
                "priority" => 10,
                "desktop_enabled" => true,
                "mobile_enabled" => true,
                "public_placement_id" => "30997976",
                "public_config" => [
                    "width" => 300,
                    "height" => 250,
                    "traffic_weight" => 20,
                    "tag_key" => "12cfe7c68a20477af04f0bab7e945751",
                    "zone_id" => "6018450",
                ],
                "notes" => "Xurvexa Video Banner 300x250. Adsterra approved Placement ID 30997976, zone 6018450. Weighted rotation share 20%.",
            ]
        );
});

echo "ADSTERRA_DB_CONFIG=PASS" . PHP_EOL;
'

php artisan optimize:clear

echo "ADSTERRA_CACHE_CLEAR=PASS"
'@

$DatabaseScript | ssh -i $KeyFile $Server 'bash -s'

Write-Host ""
Write-Host "===== FINAL NETWORK / ROTATION CHECK ====="

$FinalCheck = @'
set -euo pipefail

cd /var/www/project-ares/backend

php artisan tinker --execute='
use App\Models\AdNetwork;
use App\Models\AdPlacement;
use App\Services\Monetization\AdPlacementSelector;

echo "NETWORKS" . PHP_EOL;

AdNetwork::query()
    ->whereIn("slug", ["exoclick", "adsterra"])
    ->orderBy("id")
    ->get()
    ->each(function ($network): void {
        echo $network->slug
            . "|active=" . ($network->is_active ? "1" : "0")
            . "|priority=" . $network->priority
            . "|banner=" . ($network->supports_banner ? "1" : "0")
            . PHP_EOL;
    });

echo "PLACEMENTS" . PHP_EOL;

AdPlacement::query()
    ->with("network")
    ->where("placement_key", "video_banner")
    ->orderBy("id")
    ->get()
    ->each(function ($placement): void {
        $config = $placement->public_config ?? [];

        echo ($placement->network?->slug ?? "NULL")
            . "|placement_id=" . $placement->id
            . "|active=" . ($placement->is_active ? "1" : "0")
            . "|priority=" . $placement->priority
            . "|weight=" . ($config["traffic_weight"] ?? "NULL")
            . "|public_id=" . ($placement->public_placement_id ?? "NULL")
            . PHP_EOL;
    });

$selector = app(AdPlacementSelector::class);

$counts = [
    "exoclick" => 0,
    "adsterra" => 0,
    "other" => 0,
];

for ($i = 0; $i < 1000; $i++) {
    $placement = $selector->select(
        placementKey: "video_banner",
        format: "banner",
        isMobile: false
    );

    $slug = $placement?->network?->slug ?? "other";

    if (! array_key_exists($slug, $counts)) {
        $slug = "other";
    }

    $counts[$slug]++;
}

echo "ROTATION_SAMPLE=1000" . PHP_EOL;
echo "EXOCLICK_SELECTED=" . $counts["exoclick"] . PHP_EOL;
echo "ADSTERRA_SELECTED=" . $counts["adsterra"] . PHP_EOL;
echo "OTHER_SELECTED=" . $counts["other"] . PHP_EOL;

if (
    $counts["exoclick"] < 1
    || $counts["adsterra"] < 1
    || $counts["other"] !== 0
) {
    throw new RuntimeException(
        "Weighted rotation smoke failed."
    );
}

echo "ADSTERRA_ROTATION_SMOKE=PASS" . PHP_EOL;
'

echo "ADSTERRA_80_20_INSTALL=PASS"
'@

$FinalCheck | ssh -i $KeyFile $Server 'bash -s'
