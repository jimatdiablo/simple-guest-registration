$ErrorActionPreference = 'Stop'

$toolsDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectPath = Split-Path -Parent $toolsDir
$workspaceRoot = Split-Path -Parent (Split-Path -Parent $projectPath)
Set-Location $workspaceRoot

$projectRelativePath = Join-Path 'projects' 'Simple-Guest-Registration'
$restoreDir = Join-Path $projectPath 'restorepoints'
$timestamp = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$zipName = "SimpleGuestService_$timestamp.zip"
$excludedTopLevelNames = @(
    '.git',
    '.env',
    'data',
    'restorepoints'
)
$excludedTopLevelPatterns = @(
    '.env.*',
    '*.zip',
    '*.log',
    '*.tmp'
)

if (-not (Test-Path -Path $projectPath -PathType Container)) {
    throw "Project folder not found: $projectPath"
}

New-Item -ItemType Directory -Force -Path $restoreDir | Out-Null

$zipPath = Join-Path $restoreDir $zipName
$sourceItems = Get-ChildItem -LiteralPath $projectPath -Force | Where-Object {
    $name = $_.Name
    if ($name -eq '.env.example') {
        return $true
    }

    if ($excludedTopLevelNames -contains $name) {
        return $false
    }

    foreach ($pattern in $excludedTopLevelPatterns) {
        if ($name -like $pattern) {
            return $false
        }
    }

    return $true
}

if (-not $sourceItems) {
    throw "No files found to archive from: $projectPath"
}

$stagingRoot = Join-Path ([System.IO.Path]::GetTempPath()) "sgr_restore_$timestamp"
try {
    New-Item -ItemType Directory -Force -Path $stagingRoot | Out-Null

    foreach ($item in $sourceItems) {
        Copy-Item -LiteralPath $item.FullName -Destination $stagingRoot -Recurse -Force
    }

    if (Test-Path -LiteralPath $zipPath) {
        Remove-Item -LiteralPath $zipPath -Force
    }

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [System.IO.Compression.ZipFile]::CreateFromDirectory(
        $stagingRoot,
        $zipPath,
        [System.IO.Compression.CompressionLevel]::Optimal,
        $false
    )
}
finally {
    if (Test-Path -LiteralPath $stagingRoot) {
        Remove-Item -LiteralPath $stagingRoot -Recurse -Force
    }
}

Write-Output "Created restore point: $zipName (source: $projectRelativePath)"
Write-Output "Excluded local-only paths: $($excludedTopLevelNames -join ', ')"
