$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$PackageDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectOps = 'C:\Projects\Project-Ares\ops\video-import'
$Key = 'C:\Users\Pc\.ssh\xurvexa_mojo_rsa'
$Target = 'ubuntu@185.94.236.129'
$Stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$RemoteStage = "/tmp/eporner-views-v1-1-$Stamp"

$ExpectedHashes = [ordered]@{
    'eporner-csv-builder.py' = 'fb83e94ca78ac66f364054d4f7d332f2b4befcf16b1991bb68df70329e2d9e3f'
    'reset-eporner-views.php' = 'fbec18f0eba0aa95bc47613b46ecd67bb5f0737bbd8342bbf3e988c0aa21e983'
    'deploy-eporner-views-v1-1.sh' = '1300fb667f26841bc1175e881b9c10b3852e0a4530bd9a9ca821457e191d7855'
}

function Assert-LastExitCode {
    param([string]$Label)

    if ($LASTEXITCODE -ne 0) {
        throw "$Label failed with exit code $LASTEXITCODE."
    }
}

Write-Host '===== EPORNER VIEWS V1.1 LOCAL PACKAGE PRECHECK ====='

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

Write-Host 'EPORNER_VIEWS_V1_1_PACKAGE_PRECHECK=PASS'
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
    Write-Host '===== PRODUCTION PATCH + VIEW RESET ====='

    & ssh `
        -o ServerAliveInterval=15 `
        -o ServerAliveCountMax=6 `
        -i $Key `
        $Target `
        "bash '$RemoteStage/deploy-eporner-views-v1-1.sh' '$RemoteStage'"
    Assert-LastExitCode 'Production Eporner views V1.1 deployment'

    Write-Host ''
    Write-Host '===== LOCAL PROJECT SYNC ====='

    if (-not (Test-Path -LiteralPath $ProjectOps -PathType Container)) {
        throw "Local project ops directory not found: $ProjectOps"
    }

    $LocalBackupRoot = Join-Path $ProjectOps 'backups'
    $LocalBackup = Join-Path $LocalBackupRoot "eporner-views-v1-1-$Stamp"
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
    Write-Host 'EPORNER_VIEWS_V1_1_ALL_COMPLETE=PASS'
}
finally {
    & ssh `
        -o ServerAliveInterval=15 `
        -o ServerAliveCountMax=6 `
        -i $Key `
        $Target `
        "rm -rf '$RemoteStage'" 2>$null
}
