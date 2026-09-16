$ErrorActionPreference = "Stop"

$Key = "C:\Users\Pc\.ssh\xurvexa_mojo_rsa"
$Target = "ubuntu@185.94.236.129"
$ScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$Package = Join-Path $ScriptRoot "xurvexa-video-sitemap-v2-server.zip"
$ExpectedPackageSha = "0da4624470ff8e26d1f8b6f19bbc6bcf99e1361aaf46cc0bb140c3574e8b5084"
$RemotePackage = "/tmp/xurvexa-video-sitemap-v2-server.zip"
$RemoteDir = "/tmp/xurvexa-video-sitemap-v2"

Write-Host "===== VIDEO SITEMAP V2 WINDOWS DEPLOY ====="

if (-not (Test-Path -LiteralPath $Key)) {
    throw "SSH key not found: $Key"
}

if (-not (Test-Path -LiteralPath $Package)) {
    throw "Server package not found: $Package"
}

$ActualPackageSha = (Get-FileHash -Algorithm SHA256 -LiteralPath $Package).Hash.ToLowerInvariant()
Write-Host "SERVER_PACKAGE=$Package"
Write-Host "SERVER_PACKAGE_SHA256=$ActualPackageSha"

if ($ActualPackageSha -ne $ExpectedPackageSha) {
    throw "Server package SHA256 mismatch. Expected $ExpectedPackageSha but found $ActualPackageSha."
}

Write-Host "PACKAGE_INTEGRITY=PASS"
Write-Host ""
Write-Host "===== UPLOAD PACKAGE ====="

& scp `
    -o ServerAliveInterval=15 `
    -o ServerAliveCountMax=6 `
    -i $Key `
    $Package `
    "${Target}:$RemotePackage"

if ($LASTEXITCODE -ne 0) {
    throw "Package upload failed with exit code $LASTEXITCODE."
}

Write-Host "UPLOAD_PACKAGE=PASS"
Write-Host ""
Write-Host "===== REMOTE DEPLOY ====="

$RemoteCommand = "rm -rf $RemoteDir && mkdir -p $RemoteDir && unzip -q $RemotePackage -d $RemoteDir && bash $RemoteDir/deploy.sh"

& ssh `
    -o ServerAliveInterval=15 `
    -o ServerAliveCountMax=6 `
    -i $Key `
    $Target `
    $RemoteCommand

if ($LASTEXITCODE -ne 0) {
    throw "Remote deployment failed with exit code $LASTEXITCODE. Review the output above."
}

Write-Host ""
Write-Host "VIDEO_SITEMAP_V2_WINDOWS_DEPLOY=PASS"
Write-Host "SITEMAP_URL=https://xurvexa.com/video-sitemap.xml"
