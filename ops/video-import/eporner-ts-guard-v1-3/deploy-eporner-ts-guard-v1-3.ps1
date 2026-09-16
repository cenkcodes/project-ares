$ErrorActionPreference = "Stop"

$PackageRoot = $PSScriptRoot
$ProjectRoot = "C:\Projects\Project-Ares\ops\video-import"
$Key = "C:\Users\Pc\.ssh\xurvexa_mojo_rsa"
$Target = "ubuntu@185.94.236.129"
$RemoteStage = "/tmp/eporner-ts-guard-v1-3-" + (Get-Date -Format "yyyyMMdd-HHmmss")

$Expected = @{
    "eporner-api-collector.py" = "0b67be80670a2abe4a76a90cd982abfc618627060a03e92b58a787b5d6de6d6d"
    "eporner-csv-builder.py" = "28b2d0c7cf449a06f0a8634380f1c44912250f8ebb1a21c0b5c6c59818e42f46"
}

Write-Host "===== EPORNER TS GUARD V1.3 LOCAL PACKAGE PRECHECK ====="
foreach ($Name in $Expected.Keys) {
    $Path = Join-Path $PackageRoot $Name
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "Missing package file: $Path"
    }
    $Actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLowerInvariant()
    Write-Host "$Name=$Actual"
    if ($Actual -ne $Expected[$Name]) {
        throw "Hash mismatch: $Name"
    }
}
Write-Host "EPORNER_TS_GUARD_V1_3_LOCAL_PRECHECK=PASS"

Write-Host ""
Write-Host "===== REMOTE STAGE ====="
& scp -r -i $Key $PackageRoot "${Target}:$RemoteStage"
if ($LASTEXITCODE -ne 0) { throw "SCP upload failed." }
Write-Host "UPLOAD=PASS"

Write-Host ""
Write-Host "===== PRODUCTION PATCH + PREPARE-ONLY SMOKE ====="
& ssh `
    -o ServerAliveInterval=15 `
    -o ServerAliveCountMax=6 `
    -i $Key `
    $Target `
    "bash '$RemoteStage/deploy-eporner-ts-guard-v1-3.sh'"
if ($LASTEXITCODE -ne 0) { throw "Remote deployment failed." }

Write-Host ""
Write-Host "===== LOCAL PROJECT SYNC ====="
$BackupRoot = Join-Path $ProjectRoot ("backups\eporner-ts-guard-v1-3-" + (Get-Date -Format "yyyyMMdd-HHmmss"))
New-Item -ItemType Directory -Force -Path $BackupRoot | Out-Null

foreach ($Name in @("eporner-api-collector.py", "eporner-csv-builder.py")) {
    $Destination = Join-Path $ProjectRoot $Name
    if (Test-Path -LiteralPath $Destination -PathType Leaf) {
        Copy-Item -LiteralPath $Destination -Destination (Join-Path $BackupRoot $Name) -Force
    }
    Copy-Item -LiteralPath (Join-Path $PackageRoot $Name) -Destination $Destination -Force
    $Actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $Destination).Hash.ToLowerInvariant()
    Write-Host "LOCAL_$Name=$Actual"
    if ($Actual -ne $Expected[$Name]) {
        throw "Local sync hash mismatch: $Name"
    }
}

Write-Host "LOCAL_BACKUP=$BackupRoot"
Write-Host "LOCAL_PROJECT_SYNC=PASS"
Write-Host "EPORNER_TS_GUARD_V1_3_ALL_COMPLETE=PASS"
