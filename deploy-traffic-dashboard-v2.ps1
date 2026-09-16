$ErrorActionPreference = "Stop"

$PageLocal = "C:\Projects\Project-Ares\backend\app\Filament\Pages\TrafficRevenue.php"
$ViewLocal = "C:\Projects\Project-Ares\backend\resources\views\filament\pages\traffic-revenue.blade.php"

$PageExpected = "ee16e77d25d38d90418dd6d5e1e6ef9ec63a6202e3e7182ba2195315ce75f5da"
$ViewExpected = "0b0707a9db5a205969c36775d658c5c3ea36483ab13856764cf6e9dd18d634b3"

$KeyFile = "C:\Users\Pc\.ssh\xurvexa_mojo_rsa"
$Server = "ubuntu@185.94.236.129"

$PageRemote = "/var/www/project-ares/backend/app/Filament/Pages/TrafficRevenue.php"
$ViewRemote = "/var/www/project-ares/backend/resources/views/filament/pages/traffic-revenue.blade.php"

Write-Host ""
Write-Host "===== LOCAL V2 DASHBOARD CHECK ====="

if (!(Test-Path $PageLocal)) {
    throw "TrafficRevenue.php not found at exact project path."
}

if (!(Test-Path $ViewLocal)) {
    throw "traffic-revenue.blade.php not found at exact project path."
}

$PageActual = (Get-FileHash $PageLocal -Algorithm SHA256).Hash.ToLower()
$ViewActual = (Get-FileHash $ViewLocal -Algorithm SHA256).Hash.ToLower()

Write-Host "PAGE_SHA256=$PageActual"
Write-Host "VIEW_SHA256=$ViewActual"

if ($PageActual -ne $PageExpected) {
    throw "TrafficRevenue.php HASH MISMATCH"
}

if ($ViewActual -ne $ViewExpected) {
    throw "traffic-revenue.blade.php HASH MISMATCH"
}

Write-Host "LOCAL_DASHBOARD_V2=PASS"

Write-Host ""
Write-Host "===== PRODUCTION BACKUP ====="

ssh -i $KeyFile $Server "cp '$PageRemote' '$PageRemote.bak-20260831-v2' && cp '$ViewRemote' '$ViewRemote.bak-20260831-v2' && echo DASHBOARD_V2_BACKUP=PASS"

Write-Host ""
Write-Host "===== UPLOAD V2 DASHBOARD ====="

scp -i $KeyFile $PageLocal "${Server}:${PageRemote}"
scp -i $KeyFile $ViewLocal "${Server}:${ViewRemote}"

Write-Host ""
Write-Host "===== PRODUCTION VALIDATION ====="

$RemoteCheck = @'
set -euo pipefail

cd /var/www/project-ares/backend

php -l app/Filament/Pages/TrafficRevenue.php

PAGE_ACTUAL="$(sha256sum app/Filament/Pages/TrafficRevenue.php | awk '{print $1}')"
VIEW_ACTUAL="$(sha256sum resources/views/filament/pages/traffic-revenue.blade.php | awk '{print $1}')"

PAGE_EXPECTED="ee16e77d25d38d90418dd6d5e1e6ef9ec63a6202e3e7182ba2195315ce75f5da"
VIEW_EXPECTED="0b0707a9db5a205969c36775d658c5c3ea36483ab13856764cf6e9dd18d634b3"

echo "PRODUCTION_PAGE_SHA256=$PAGE_ACTUAL"
echo "PRODUCTION_VIEW_SHA256=$VIEW_ACTUAL"

test "$PAGE_ACTUAL" = "$PAGE_EXPECTED"
test "$VIEW_ACTUAL" = "$VIEW_EXPECTED"

grep -q 'traffic_unique_visitors' app/Filament/Pages/TrafficRevenue.php
grep -q 'traffic_second_video_rate' app/Filament/Pages/TrafficRevenue.php
grep -q 'xrv-traffic-v2' resources/views/filament/pages/traffic-revenue.blade.php
grep -q 'Eligibility Rate' resources/views/filament/pages/traffic-revenue.blade.php

php artisan optimize:clear
php artisan view:cache

echo "DASHBOARD_V2=PASS"
'@

$RemoteCheck | ssh -i $KeyFile $Server 'bash -s'
