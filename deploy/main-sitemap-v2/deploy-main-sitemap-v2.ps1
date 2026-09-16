$ErrorActionPreference = "Stop"

$Key = "C:\Users\Pc\.ssh\xurvexa_mojo_rsa"
$Target = "ubuntu@185.94.236.129"
$LocalRoot = "C:\Projects\Project-Ares\deploy\main-sitemap-v2"
$ServerPackage = Join-Path $LocalRoot "xurvexa-main-sitemap-v2-server.zip"
$ExpectedSha = "67ce5a5dc2b7e5ccc85cf46bcc9c7c6b1654e6baaa11484a50f6c6c5bfbf4fee"
$RemoteZip = "/tmp/xurvexa-main-sitemap-v2-server.zip"
$RemoteDir = "/tmp/xurvexa-main-sitemap-v2"

Write-Host "===== MAIN SITEMAP V2 WINDOWS DEPLOY ====="

if (-not (Test-Path $ServerPackage)) {
    throw "Server package not found: $ServerPackage"
}

$ActualSha = (Get-FileHash -Algorithm SHA256 -Path $ServerPackage).Hash.ToLowerInvariant()
Write-Host "SERVER_PACKAGE=$ServerPackage"
Write-Host "SERVER_PACKAGE_SHA256=$ActualSha"

if ($ActualSha -ne $ExpectedSha) {
    throw "PACKAGE_INTEGRITY=FAIL"
}

Write-Host "PACKAGE_INTEGRITY=PASS"
Write-Host ""
Write-Host "===== UPLOAD PACKAGE ====="

& scp -i $Key $ServerPackage "${Target}:$RemoteZip"
if ($LASTEXITCODE -ne 0) {
    throw "Upload failed exit $LASTEXITCODE"
}
Write-Host "UPLOAD_PACKAGE=PASS"

Write-Host ""
Write-Host "===== REMOTE DEPLOY ====="

$RemoteScript = @"
set -euo pipefail
rm -rf '$RemoteDir'
mkdir -p '$RemoteDir'
python3 -m zipfile -e '$RemoteZip' '$RemoteDir'
chmod +x '$RemoteDir/deploy.sh' '$RemoteDir/rollback.sh'
bash '$RemoteDir/deploy.sh'
"@

$RemoteScript = $RemoteScript.Replace("`r", "")
$RemoteScript | ssh -o ServerAliveInterval=15 -o ServerAliveCountMax=6 -i $Key $Target "bash -s"

if ($LASTEXITCODE -ne 0) {
    throw "Remote deployment failed exit $LASTEXITCODE"
}

Write-Host ""
Write-Host "MAIN_SITEMAP_V2_WINDOWS_DEPLOY=PASS"
Write-Host "SITEMAP_URL=https://xurvexa.com/sitemap.xml"
Write-Host "VIDEO_SITEMAP_URL=https://xurvexa.com/video-sitemap.xml"
