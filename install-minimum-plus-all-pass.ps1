$ErrorActionPreference = "Stop"

$ProjectRoot = "C:\Projects\Project-Ares"
$LocalBatch = Join-Path $ProjectRoot "ops\video-import\xvideos-batch.php"
$Key = "C:\Users\Pc\.ssh\xurvexa_mojo_rsa"
$SshTarget = "ubuntu@185.94.236.129"

$RemoteBatch = "/var/www/project-ares/ops/video-import/xvideos-batch.php"
$RemoteTemp = "/tmp/xvideos-batch-minimum-plus-all-pass.php"
$RemoteBackup = "/var/www/project-ares/ops/video-import/xvideos-batch.php.bak-20260831-minimum-plus-all-pass"

$ExpectedOldHash = "44f5acb72247fee6ad77e737f95a0c53efa40d0396d334c90cabd2ed52000475"
$ExpectedNewHash = "f72fe4f43ad631b3eb1b49f0da5b09503ae8aef2d715eace3f68ac8c0f5d8911"

Write-Host ""
Write-Host "===== LOCAL MINIMUM+ALL-PASS CHECK ====="

if (-not (Test-Path -LiteralPath $LocalBatch)) {
    throw "Missing local orchestrator: $LocalBatch"
}

$LocalHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $LocalBatch).Hash.ToLowerInvariant()
Write-Host "LOCAL_XVIDEOS_BATCH_SHA256=$LocalHash"

if ($LocalHash -ne $ExpectedNewHash) {
    throw "Local xvideos-batch.php hash mismatch."
}

Write-Host "LOCAL_MINIMUM_ALL_PASS=PASS"

Write-Host ""
Write-Host "===== UPLOAD COMPLETE REPLACEMENT ====="

scp -i $Key $LocalBatch "${SshTarget}:${RemoteTemp}"
if ($LASTEXITCODE -ne 0) {
    throw "Temporary upload failed."
}

$RemoteScript = @'
set -euo pipefail

REMOTE="/var/www/project-ares/ops/video-import/xvideos-batch.php"
TEMP="/tmp/xvideos-batch-minimum-plus-all-pass.php"
BACKUP="/var/www/project-ares/ops/video-import/xvideos-batch.php.bak-20260831-minimum-plus-all-pass"
OLD="44f5acb72247fee6ad77e737f95a0c53efa40d0396d334c90cabd2ed52000475"
NEW="f72fe4f43ad631b3eb1b49f0da5b09503ae8aef2d715eace3f68ac8c0f5d8911"

test -f "$REMOTE"
test -f "$TEMP"

TEMP_HASH="$(sha256sum "$TEMP" | awk '{print $1}')"
echo "TEMP_SHA256=$TEMP_HASH"

if [ "$TEMP_HASH" != "$NEW" ]; then
    echo "ERROR=Temporary upload hash mismatch." >&2
    exit 1
fi

CURRENT="$(sha256sum "$REMOTE" | awk '{print $1}')"
echo "PRODUCTION_OLD_SHA256=$CURRENT"

if [ "$CURRENT" = "$OLD" ]; then
    sudo cp "$REMOTE" "$BACKUP"
    sudo install -m 0755 "$TEMP" "$REMOTE"
    echo "MINIMUM_ALL_PASS_BACKUP=PASS"
elif [ "$CURRENT" = "$NEW" ]; then
    echo "MINIMUM_ALL_PASS_ALREADY_INSTALLED=YES"
else
    echo "ERROR=Unexpected production hash: $CURRENT" >&2
    rm -f "$TEMP"
    exit 1
fi

rm -f "$TEMP"

php -l "$REMOTE"

FINAL="$(sha256sum "$REMOTE" | awk '{print $1}')"
echo "PRODUCTION_NEW_SHA256=$FINAL"

if [ "$FINAL" != "$NEW" ]; then
    echo "ERROR=Production hash mismatch after install." >&2
    exit 1
fi

echo "MINIMUM_ALL_PASS_ORCHESTRATOR=PASS"
'@

$RemoteScript = $RemoteScript.Replace("44f5acb72247fee6ad77e737f95a0c53efa40d0396d334c90cabd2ed52000475", $ExpectedOldHash)
$RemoteScript = $RemoteScript.Replace("f72fe4f43ad631b3eb1b49f0da5b09503ae8aef2d715eace3f68ac8c0f5d8911", $ExpectedNewHash)
$RemoteScript = $RemoteScript.Replace("`r", "")

$RemoteScript | ssh -i $Key $SshTarget "bash -s"
if ($LASTEXITCODE -ne 0) {
    throw "Production minimum+all-pass installation failed."
}

Write-Host ""
Write-Host "===== INSTALL COMPLETE ====="
Write-Host "MINIMUM_ALL_PASS_INSTALL=PASS"
