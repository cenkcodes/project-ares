$ErrorActionPreference = "Stop"

$Key = "C:\Users\Pc\.ssh\xurvexa_mojo_rsa"
$Target = "ubuntu@185.94.236.129"
$ScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$Package = Join-Path $ScriptRoot "xurvexa-main-sitemap-v2.1-server.zip"
$ExpectedPackageSha = "67ce5a5dc2b7e5ccc85cf46bcc9c7c6b1654e6baaa11484a50f6c6c5bfbf4fee"
$RemotePackage = "/tmp/xurvexa-main-sitemap-v2.1-server.zip"
$RemoteDir = "/tmp/xurvexa-main-sitemap-v2.1"

Write-Host "===== MAIN SITEMAP V2.1 WINDOWS DEPLOY ====="

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
Write-Host "===== REMOTE PREFLIGHT + DEPLOY ====="

$RemoteCommand = "command -v python3 >/dev/null 2>&1 && command -v sha256sum >/dev/null 2>&1 && echo REMOTE_COMMANDS=PASS && sha256sum '$RemotePackage' | grep -q '^$ExpectedPackageSha ' && echo REMOTE_PACKAGE_INTEGRITY=PASS && rm -rf '$RemoteDir' && mkdir -p '$RemoteDir' && python3 -m zipfile -e '$RemotePackage' '$RemoteDir' && test -f '$RemoteDir/deploy.sh' && test -f '$RemoteDir/rollback.sh' && test -f '$RemoteDir/resources/views/seo/sitemap.blade.php' && echo REMOTE_EXTRACTION=PASS && bash -n '$RemoteDir/deploy.sh' && bash -n '$RemoteDir/rollback.sh' && echo REMOTE_SCRIPT_SYNTAX=PASS && bash '$RemoteDir/deploy.sh'"

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
Write-Host "MAIN_SITEMAP_V2_1_WINDOWS_DEPLOY=PASS"
Write-Host "SITEMAP_URL=https://xurvexa.com/sitemap.xml"
Write-Host "VIDEO_SITEMAP_URL=https://xurvexa.com/video-sitemap.xml"
