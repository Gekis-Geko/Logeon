param(
    [ValidateSet("all", "ready", "source", "source-dev")]
    [string]$Variant = "all",
    [string]$OutputRoot = "dist/release",
    [string]$StagingRoot = "dist/release/staging",
    [switch]$ReadyIncludeJsSource = $false,
    [switch]$SkipReadySmokeChecks = $false
)

$ErrorActionPreference = "Stop"

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot "..\..")
$outputRootAbs = Join-Path $repoRoot $OutputRoot
$stagingRootAbs = Join-Path $repoRoot $StagingRoot

$excludeExactFiles = @(
    "configs/db.php",
    "configs/installed.php"
)

$excludePrefixDirsBase = @(
    ".git/",
    ".claude/",
    ".pr/",
    "node_modules/",
    "dist/",
    "tmp/",
    "tmp/cache/",
    "tmp/twig-cache/",
    "tmp/uploads/",
    "tmp/uploader/",
    "tmp/build-meta/",
    "assets/imgs/uploads/",
    "logs/",
    "modules/"
)

$excludeRegexBase = @(
    "^\.env(\..+)?$",
    "\.log$"
)

$excludeFileNamesReady = @(
    ".gitignore",
    ".gitlab-ci.yml",
    ".gitattributes",
    ".editorconfig"
)

function Normalize-RelativePath {
    param([string]$AbsolutePath, [string]$Root)

    $relative = $AbsolutePath.Substring($Root.Length).TrimStart("\", "/")
    return ($relative -replace "\\", "/")
}

function Should-Exclude {
    param(
        [string]$RelativePath,
        [hashtable]$Profile
    )

    $rel = $RelativePath.ToLowerInvariant()
    $includeVendor = [bool]($Profile.IncludeVendor)
    $excludePrefixDirs = @($Profile.ExcludePrefixDirs)
    $excludeRegex = @($Profile.ExcludeRegex)
    $excludeFileNamesAny = @($Profile.ExcludeFileNamesAny)

    foreach ($exact in $excludeExactFiles) {
        if ($rel -eq $exact.ToLowerInvariant()) {
            return $true
        }
    }

    foreach ($prefix in $excludePrefixDirs) {
        if ($rel.StartsWith($prefix.ToLowerInvariant())) {
            return $true
        }
    }

    $fileName = [System.IO.Path]::GetFileName($rel)
    foreach ($excludedName in $excludeFileNamesAny) {
        if ($fileName -eq $excludedName.ToLowerInvariant()) {
            return $true
        }
    }

    if (-not $IncludeVendor -and $rel.StartsWith("vendor/")) {
        return $true
    }

    foreach ($pattern in $excludeRegex) {
        if ($rel -match $pattern) {
            return $true
        }
    }

    return $false
}

function Build-Package {
    param(
        [string]$PackageName,
        [hashtable]$Profile,
        [object[]]$AllFiles
    )

    $stagingPackageAbs = Join-Path $stagingRootAbs $PackageName
    $zipPath = Join-Path $outputRootAbs ("{0}.zip" -f $PackageName)

    if (Test-Path $stagingPackageAbs) {
        Remove-Item -Recurse -Force -LiteralPath $stagingPackageAbs
    }
    if (Test-Path $zipPath) {
        Remove-Item -Force -LiteralPath $zipPath
    }

    New-Item -ItemType Directory -Force -Path $stagingPackageAbs | Out-Null

    # Mantiene la cartella modules nel pacchetto core, ma senza moduli inclusi.
    $modulesDir = Join-Path $stagingPackageAbs "modules"
    New-Item -ItemType Directory -Force -Path $modulesDir | Out-Null
    New-Item -ItemType File -Force -Path (Join-Path $modulesDir ".gitkeep") | Out-Null

    # Include cartelle runtime vuote (con sottocartelle), senza includere i file contenuti.
    $directorySkeletonRoots = @(
        "assets/imgs/uploads",
        "tmp"
    )
    foreach ($rootRel in $directorySkeletonRoots) {
        $sourceRootDir = Join-Path $repoRoot $rootRel
        if (-not (Test-Path -LiteralPath $sourceRootDir -PathType Container)) {
            continue
        }

        $targetRootDir = Join-Path $stagingPackageAbs $rootRel
        New-Item -ItemType Directory -Force -Path $targetRootDir | Out-Null

        Get-ChildItem -LiteralPath $sourceRootDir -Recurse -Directory -Force | ForEach-Object {
            $relativePath = Normalize-RelativePath -AbsolutePath $_.FullName -Root $repoRoot
            $targetDir = Join-Path $stagingPackageAbs $relativePath
            New-Item -ItemType Directory -Force -Path $targetDir | Out-Null
        }
    }

    $copied = 0
    foreach ($file in $AllFiles) {
        $source = $file.FullName
        $relativePath = Normalize-RelativePath -AbsolutePath $source -Root $repoRoot

        if (Should-Exclude -RelativePath $relativePath -Profile $Profile) {
            continue
        }

        $destination = Join-Path $stagingPackageAbs $relativePath
        $destinationDir = Split-Path -Path $destination -Parent
        if (-not (Test-Path $destinationDir)) {
            New-Item -ItemType Directory -Force -Path $destinationDir | Out-Null
        }

        Copy-Item -LiteralPath $source -Destination $destination -Force
        $copied++
    }

    Compress-Archive -Path (Join-Path $stagingPackageAbs "*") -DestinationPath $zipPath -Force

    Write-Host "Pacchetto creato: $zipPath"
    Write-Host "Staging: $stagingPackageAbs"
    Write-Host "File copiati: $copied"
}

function Force-ReadyFrontendBundleMode {
    param(
        [string]$StagingPackageAbs
    )

    $appConfigPath = Join-Path $StagingPackageAbs "configs/app.php"
    if (-not (Test-Path -LiteralPath $appConfigPath -PathType Leaf)) {
        Write-Host "[ready] configs/app.php non trovato, skip override pilot_bundle_mode."
        return
    }

    $content = Get-Content -LiteralPath $appConfigPath -Raw
    $updated = $content -replace "('pilot_bundle_mode'\s*=>\s*)'[^']+'", "`$1'on'"

    if ($updated -ne $content) {
        Set-Content -LiteralPath $appConfigPath -Value $updated -Encoding UTF8
        Write-Host "[ready] Override frontend applicato: pilot_bundle_mode='on' in configs/app.php"
    } else {
        Write-Host "[ready] Nessuna sostituzione effettuata su pilot_bundle_mode (formato inatteso)."
    }
}

New-Item -ItemType Directory -Force -Path $outputRootAbs | Out-Null
New-Item -ItemType Directory -Force -Path $stagingRootAbs | Out-Null

$allFiles = Get-ChildItem -Path $repoRoot -Recurse -File -Force

$variantNormalized = if ($Variant -eq "source") { "source-dev" } else { $Variant }

$profiles = @{
    "ready" = @{
        IncludeVendor = $true
        ExcludePrefixDirs = @($excludePrefixDirsBase + @(
            "scripts/",
            "docs/interno/",
            "assets/sass/"
        ))
        ExcludeRegex = @($excludeRegexBase)
        ExcludeFileNamesAny = @($excludeFileNamesReady)
    }
    "source-dev" = @{
        IncludeVendor = $false
        ExcludePrefixDirs = @($excludePrefixDirsBase + @(
            "docs/adr/",
            "docs/constraints/",
            "docs/interno/"
        ))
        ExcludeRegex = @($excludeRegexBase)
        ExcludeFileNamesAny = @()
    }
}

$packages = @()
if ($variantNormalized -in @("all", "ready")) {
    if (-not (Test-Path (Join-Path $repoRoot "vendor"))) {
        throw "Cartella vendor assente: impossibile generare il pacchetto ready."
    }

    if (-not $ReadyIncludeJsSource) {
        $profiles["ready"].ExcludePrefixDirs += @(
            "assets/js/app/",
            "assets/js/components/",
            "assets/js/services/"
        )
    }

    $packages += @{ Name = "logeon-core-ready"; Profile = $profiles["ready"] }
}
if ($variantNormalized -in @("all", "source-dev")) {
    $packages += @{ Name = "logeon-core-source-dev"; Profile = $profiles["source-dev"] }
}

foreach ($pkg in $packages) {
    Write-Host ""
    Write-Host "== Build $($pkg.Name) =="
    Build-Package -PackageName $pkg.Name -Profile ([hashtable]$pkg.Profile) -AllFiles $allFiles

    # Minifica i CSS solo nel pacchetto ready, mantenendo i nomi canonici (.css) + source map esterna.
    if ($pkg.Name -eq "logeon-core-ready") {
        $readyStaging = Join-Path $stagingRootAbs $pkg.Name
        Force-ReadyFrontendBundleMode -StagingPackageAbs $readyStaging

        $cssTarget = Join-Path (Join-Path $stagingRootAbs $pkg.Name) "assets/css"
        if (Test-Path $cssTarget) {
            Write-Host "[ready] Minificazione CSS in corso: $cssTarget"
            node scripts/build/css-minify.mjs --target "$cssTarget"
        }

        if ($SkipReadySmokeChecks) {
            Write-Host "[ready] Smoke checks saltati (-SkipReadySmokeChecks)."
        } elseif ($ReadyIncludeJsSource) {
            Write-Host "[ready] Smoke dist-only saltato: rollback JS source attivo (-ReadyIncludeJsSource)."
        } else {
            Write-Host "[ready] Smoke dist-only in corso: $readyStaging"
            node scripts/release/smoke-ready-dist-only-js.mjs --staging "$readyStaging"
        }

        $zipPath = Join-Path $outputRootAbs ("{0}.zip" -f $pkg.Name)
        if (Test-Path $zipPath) {
            Remove-Item -Force -LiteralPath $zipPath
        }
        Compress-Archive -Path (Join-Path (Join-Path $stagingRootAbs $pkg.Name) "*") -DestinationPath $zipPath -Force
        Write-Host "[ready] ZIP aggiornato dopo minificazione CSS: $zipPath"
    }
}
