$ErrorActionPreference = "Stop"

$Key = "C:\Users\Pc\.ssh\xurvexa_mojo_rsa"
$Target = "ubuntu@185.94.236.129"
$ProjectRoot = "C:\Projects\Project-Ares"
$Package = Join-Path $ProjectRoot "deploy\video-import-center-v1\xurvexa-video-import-center-v1.zip"
$RemoteZip = "/tmp/xurvexa-video-import-center-v1.zip"
$RemoteDir = "/tmp/xurvexa-video-import-center-v1"

if (-not (Test-Path $Package)) {
    throw "Package not found: $Package"
}

Write-Host "===== UPLOAD PACKAGE ====="
scp `
    -i $Key `
    $Package `
    "${Target}:${RemoteZip}"

Write-Host "`n===== DEPLOY PACKAGE ====="
ssh `
    -o ServerAliveInterval=15 `
    -o ServerAliveCountMax=6 `
    -i $Key `
    $Target `
    "rm -rf '$RemoteDir' && mkdir -p '$RemoteDir' && unzip -q '$RemoteZip' -d '$RemoteDir' && bash '$RemoteDir/deploy-video-import-center-v1.sh'"
