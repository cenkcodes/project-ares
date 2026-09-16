$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$PackageDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectOps = 'C:\Projects\Project-Ares\ops\video-import'
$Key = 'C:\Users\Pc\.ssh\xurvexa_mojo_rsa'
$Target = 'ubuntu@185.94.236.129'
$Stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$RemoteStage = "/tmp/eporner-provider-v1-$Stamp"

$ExpectedHashes = [ordered]@{
    'eporner-api-collector.py' = '94e27c4e388941f98bceb5dc77d9777fffb0cc1eb3da082ea7fa399936b21410'
    'eporner-csv-builder.py' = '0f929cba3e3bf6c7a84bf5af28bef711186850f8287426a768ed4b0005ac4eba'
    'eporner-batch.php' = '6af038866edf286657a5eae5049ba275b31c82984f183573b53acd12a7f8c72c'
    'eporner-provider-bootstrap.php' = '741a567e17793f980e77e8c06c048ef23729bbd0c8836201918696bf475509a7'
    'deploy-eporner-provider-v1.sh' = '0a8c6f9a6a1c6290f4c24853874b05d13d96ddb497ed89d714331649a2c32cb7'
}

function Assert-LastExitCode {
    param([string]$Label)

    if ($LASTEXITCODE -ne 0) {
        throw "$Label failed with exit code $LASTEXITCODE."
    }
}

Write-Host '===== EPORNER PROVIDER V1 LOCAL PACKAGE PRECHECK ====='

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

Write-Host 'EPORNER_PROVIDER_V1_PACKAGE_PRECHECK=PASS'
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
    Write-Host '===== PRODUCTION DEPLOY + PREPARE-ONLY SMOKE ====='

    & ssh `
        -o ServerAliveInterval=15 `
        -o ServerAliveCountMax=6 `
        -i $Key `
        $Target `
        "bash '$RemoteStage/deploy-eporner-provider-v1.sh' '$RemoteStage'"
    Assert-LastExitCode 'Production Eporner V1 deployment'

    Write-Host ''
    Write-Host '===== LOCAL PROJECT SYNC ====='

    if (-not (Test-Path -LiteralPath $ProjectOps -PathType Container)) {
        throw "Local project ops directory not found: $ProjectOps"
    }

    $LocalBackupRoot = Join-Path $ProjectOps 'backups'
    $LocalBackup = Join-Path $LocalBackupRoot "eporner-provider-v1-$Stamp"
    New-Item -ItemType Directory -Path $LocalBackup -Force | Out-Null

    $RuntimeFiles = @(
        'eporner-api-collector.py',
        'eporner-csv-builder.py',
        'eporner-batch.php',
        'eporner-provider-bootstrap.php'
    )

    foreach ($Name in $RuntimeFiles) {
        $Destination = Join-Path $ProjectOps $Name

        if (Test-Path -LiteralPath $Destination -PathType Leaf) {
            Copy-Item -LiteralPath $Destination -Destination (Join-Path $LocalBackup $Name) -Force
        }

        Copy-Item -LiteralPath (Join-Path $PackageDir $Name) -Destination $Destination -Force

        $Actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $Destination).Hash.ToLowerInvariant()
        $Expected = $ExpectedHashes[$Name]
        Write-Host "LOCAL_$Name=$Actual"

        if ($Actual -ne $Expected) {
            throw "Local project sync hash mismatch for $Name."
        }
    }

    Write-Host "LOCAL_BACKUP=$LocalBackup"
    Write-Host 'LOCAL_PROJECT_SYNC=PASS'
    Write-Host 'EPORNER_PROVIDER_V1_ALL_COMPLETE=PASS'
}
finally {
    & ssh `
        -o ServerAliveInterval=15 `
        -o ServerAliveCountMax=6 `
        -i $Key `
        $Target `
        "rm -rf '$RemoteStage'" 2>$null
}
