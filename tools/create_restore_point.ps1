$ErrorActionPreference = 'Stop'

$toolsDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectPath = Split-Path -Parent $toolsDir
$workspaceRoot = Split-Path -Parent (Split-Path -Parent $projectPath)
Set-Location $workspaceRoot

$projectRelativePath = Join-Path 'projects' 'Simple-Guest-Registration'
$restoreDir = Join-Path $projectPath 'restorepoints'
$timestamp = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$zipName = "SimpleGuestService_$timestamp.zip"

if (-not (Test-Path -Path $projectPath -PathType Container)) {
    throw "Project folder not found: $projectPath"
}

New-Item -ItemType Directory -Force -Path $restoreDir | Out-Null

$zipPath = Join-Path $restoreDir $zipName
Compress-Archive -Path $projectPath -DestinationPath $zipPath -CompressionLevel Optimal -Force

Write-Output "Created restore point: $zipName (source: $projectRelativePath)"
