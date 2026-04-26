param(
    [string]$ModulesRoot = "modules",
    [string]$OutputRoot = "dist/modules"
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path $ModulesRoot)) {
    throw "Directory moduli non trovata: $ModulesRoot"
}

New-Item -ItemType Directory -Force -Path $OutputRoot | Out-Null

Get-ChildItem -Path $OutputRoot -Filter "*.zip" -File | Remove-Item -Force

$moduleDirs = Get-ChildItem -Path $ModulesRoot -Directory
foreach ($dir in $moduleDirs) {
    $manifest = Join-Path $dir.FullName "module.json"
    if (-not (Test-Path $manifest)) {
        continue
    }

    $moduleName = $dir.Name -replace '^logeon\.', ''
    $zipName = "logeon-module-$moduleName.zip"
    $zipPath = Join-Path $OutputRoot $zipName

    Compress-Archive -Path (Join-Path $dir.FullName "*") -DestinationPath $zipPath -Force
    Write-Host "Creato: $zipPath"
}

