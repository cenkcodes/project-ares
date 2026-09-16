$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$PackageDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectOps = 'C:\Projects\Project-Ares\ops\video-import'
$Key = 'C:\Users\Pc\.ssh\xurvexa_mojo_rsa'
$Target = 'ubuntu@185.94.236.129'
$Stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$RemoteStage = "/tmp/eporner-normalization-v1-2-$Stamp"

$ExpectedHashes = [ordered]@{
    'eporner-csv-builder.py' = 'de17855829272ea77bc0e67afd8bb179593a19e201ffcd9d678748473b60331d'
    'verify-source-term-normalization.php' = '05170bbc91f41079290061f207f1de6c5b8adc4d84437cc44362cfce16bda17a'
    'deploy-eporner-normalization-v1-2.sh' = 'bac986b5321432db864310f0b51a4a5c80ba3c4327292ecff7ae3f057c99bce8'
}

function Assert-LastExitCode {
    param([string]$Label)
    if ($LASTEXITCODE -ne 0) {
        throw "$Label failed with exit code $LASTEXITCODE."
    }
}

Write-Host '===== EPORNER NORMALIZATION V1.2 LOCAL PACKAGE PRECHECK ====='
foreach ($Name in $ExpectedHashes.Keys) {
    $Path = Join-Path $PackageDir $Name
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "Package file missing: $Path"
    }
    $Actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLowerInvariant()
    $Expected = $ExpectedHashes[$Name]
    Write-Host "$Name=$Actual"
    if ($Actual -ne $Expected) {
        throw "SHA256 mismatch for $Name. Expected $Expected got $Actual"
    }
}
Write-Host 'EPORNER_NORMALIZATION_V1_2_PACKAGE_PRECHECK=PASS'
Write-Host ''
Write-Host '===== REMOTE STAGE ====='

& ssh `
    -o ServerAliveInterval=15 `
    -o ServerAliveCountMax=6 `
    -i $Key `
    $Target `
    "mkdir -p '$RemoteStage' && chmod 700 '$RemoteStage'"
Assert-LastExitCode 'Remote stage creation'

try {
    foreach ($Name in $ExpectedHashes.Keys) {
        $LocalPath = Join-Path $PackageDir $Name
        & scp `
            -o ServerAliveInterval=15 `
            -o ServerAliveCountMax=6 `
            -i $Key `
            $LocalPath `
            "${Target}:$RemoteStage/$Name"
        Assert-LastExitCode "Upload $Name"
    }

    Write-Host 'UPLOAD=PASS'
    Write-Host ''
    Write-Host '===== PRODUCTION PATCH + NORMALIZATION SMOKE ====='

    & ssh `
        -o ServerAliveInterval=15 `
        -o ServerAliveCountMax=6 `
        -i $Key `
        $Target `
        "bash '$RemoteStage/deploy-eporner-normalization-v1-2.sh' '$RemoteStage'"
    Assert-LastExitCode 'Production Eporner normalization V1.2 deployment'

    Write-Host ''
    Write-Host '===== LOCAL PROJECT SYNC ====='

    if (-not (Test-Path -LiteralPath $ProjectOps -PathType Container)) {
        throw "Local project ops directory not found: $ProjectOps"
    }

    $LocalBackupRoot = Join-Path $ProjectOps 'backups'
    $LocalBackup = Join-Path $LocalBackupRoot "eporner-normalization-v1-2-$Stamp"
    New-Item -ItemType Directory -Path $LocalBackup -Force | Out-Null

    $BuilderName = 'eporner-csv-builder.py'
    $Destination = Join-Path $ProjectOps $BuilderName
    if (Test-Path -LiteralPath $Destination -PathType Leaf) {
        Copy-Item -LiteralPath $Destination -Destination (Join-Path $LocalBackup $BuilderName) -Force
    }
    Copy-Item -LiteralPath (Join-Path $PackageDir $BuilderName) -Destination $Destination -Force

    $Actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $Destination).Hash.ToLowerInvariant()
    $Expected = $ExpectedHashes[$BuilderName]
    Write-Host "LOCAL_$BuilderName=$Actual"
    if ($Actual -ne $Expected) {
        throw "Local project sync hash mismatch for $BuilderName."
    }

    Write-Host "LOCAL_BACKUP=$LocalBackup"
    Write-Host 'LOCAL_PROJECT_SYNC=PASS'
    Write-Host 'EPORNER_NORMALIZATION_V1_2_ALL_COMPLETE=PASS'
}
finally {
    & ssh `
        -o ServerAliveInterval=15 `
        -o ServerAliveCountMax=6 `
        -i $Key `
        $Target `
        "rm -rf '$RemoteStage'" 2>$null
}
