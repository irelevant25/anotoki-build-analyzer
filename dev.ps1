<#
.SYNOPSIS
  Everyday commands for working on the build analyzer on Windows.

.DESCRIPTION
  setup         Install the backend's packages (Composer) and the frontend's (npm). Then run "start" and
                open http://localhost:4600: the app sends you to setup.php, which writes the configuration.
  start         Open the API server (http://127.0.0.1:8400) and the Angular dev server
                (http://localhost:4600) in separate windows. Nothing else is needed: syncs run in steps
                from the Sources page.
  migrate       Apply the database migrations that are pending.
  sync          Read builds now (sources synced in the last 20 hours are skipped unless -Force).
  test          Run the backend and the frontend tests.
  build         Build the frontend, so that the API server alone serves everything on :8400.
  dist          Assemble dist/, what to upload to the host (see DEV.md, "Deploying").

  PHP 8.4+ is required. When "php" on PATH is older, set BUILD_ANALYZER_PHP to the php.exe to use, e.g. in
  dev.local.ps1 (not committed):  $env:BUILD_ANALYZER_PHP = 'C:\path\to\php.exe'

.EXAMPLE
  .\dev.ps1 setup
  .\dev.ps1 start
  .\dev.ps1 sync -Force
#>
param(
    [Parameter(Position = 0)]
    [ValidateSet('setup', 'start', 'migrate', 'sync', 'test', 'build', 'dist')]
    [string]$Command = 'start',
    [switch]$Force
)

$ErrorActionPreference = 'Stop'
$Root = $PSScriptRoot
$Backend = Join-Path $Root 'backend'
$Frontend = Join-Path $Root 'frontend'

$localConfig = Join-Path $Root 'dev.local.ps1'
if (Test-Path $localConfig) {
    . $localConfig
}

function Get-Php {
    $candidates = @()
    if ($env:BUILD_ANALYZER_PHP) { $candidates += $env:BUILD_ANALYZER_PHP }
    $onPath = Get-Command php -ErrorAction SilentlyContinue
    if ($onPath) { $candidates += $onPath.Source }

    foreach ($php in $candidates) {
        $version = & $php -r 'echo PHP_VERSION_ID;' 2>$null
        if ($LASTEXITCODE -eq 0 -and [int]$version -ge 80400) {
            return $php
        }
    }
    throw "PHP 8.4 or newer was not found. Put `$env:BUILD_ANALYZER_PHP = 'C:\path\to\php.exe' into dev.local.ps1."
}

function Invoke-Checked([string]$File, [string[]]$Arguments, [string]$WorkingDirectory) {
    Push-Location $WorkingDirectory
    try {
        & $File @Arguments
        if ($LASTEXITCODE -ne 0) { throw "'$File $($Arguments -join ' ')' failed with exit code $LASTEXITCODE" }
    }
    finally {
        Pop-Location
    }
}

# composer.phar, downloaded once into tools\ (gitignored) unless Composer is on PATH.
function Get-Composer([string]$Php) {
    $onPath = Get-Command composer -ErrorAction SilentlyContinue
    if ($onPath) { return $null }

    $phar = Join-Path $Root 'tools\composer.phar'
    if (-not (Test-Path $phar)) {
        Write-Host 'Downloading Composer into tools\composer.phar ...'
        New-Item -ItemType Directory -Force (Split-Path $phar) | Out-Null
        $installer = Join-Path $env:TEMP 'composer-setup.php'
        Invoke-WebRequest 'https://getcomposer.org/installer' -OutFile $installer -UseBasicParsing
        $expected = (Invoke-WebRequest 'https://composer.github.io/installer.sig' -UseBasicParsing).Content.Trim()
        $actual = (Get-FileHash $installer -Algorithm SHA384).Hash.ToLower()
        if ($expected -ne $actual) { throw 'Composer installer checksum mismatch.' }
        Invoke-Checked $Php @($installer, '--install-dir', (Split-Path $phar), '--filename', 'composer.phar', '--quiet') $Root
        Remove-Item $installer
    }
    return $phar
}

switch ($Command) {
    'setup' {
        $php = Get-Php
        Write-Host "Using $php"
        $phar = Get-Composer $php
        if ($phar) {
            Invoke-Checked $php @($phar, 'install', '--no-interaction') $Backend
        }
        else {
            Invoke-Checked 'composer' @('install', '--no-interaction') $Backend
        }

        Push-Location $Frontend
        try {
            # npm 10.9 has a dependency-resolution bug with this project; fall back to npm 11 when it hits.
            npm ci
            if ($LASTEXITCODE -ne 0) { npx -y npm@11 ci }
            if ($LASTEXITCODE -ne 0) { throw 'npm install failed.' }
        }
        finally {
            Pop-Location
        }

        Write-Host ''
        Write-Host 'Done. Next: .\dev.ps1 start, then open http://localhost:4600 - it leads you to setup.php.' -ForegroundColor Green
    }

    'start' {
        $php = Get-Php
        # No queue worker or scheduler: syncs run in steps driven by the Sources page.
        $windows = @(
            @{ Title = 'API (php -S 127.0.0.1:8400)'; Dir = $Backend; Cmd = "& '$php' -S 127.0.0.1:8400 router.php" },
            @{ Title = 'Angular dev server'; Dir = $Frontend; Cmd = 'npx ng serve --open' }
        )
        foreach ($window in $windows) {
            $script = "`$Host.UI.RawUI.WindowTitle = 'Build analyzer - $($window.Title)'; Set-Location '$($window.Dir)'; $($window.Cmd)"
            Start-Process powershell -ArgumentList '-NoExit', '-Command', $script | Out-Null
        }
        Write-Host 'Started 2 windows. The app opens at http://localhost:4600 (close the windows to stop).' -ForegroundColor Green
    }

    'migrate' {
        Invoke-Checked (Get-Php) @('migration.php') $Backend
    }

    'sync' {
        $arguments = @('sync-builds.php')
        if ($Force) { $arguments += '--force' }
        Invoke-Checked (Get-Php) $arguments $Backend
    }

    'test' {
        Invoke-Checked (Get-Php) @('tests/run.php') $Backend
        Invoke-Checked 'npx' @('ng', 'test', '--watch=false') $Frontend
    }

    'build' {
        Invoke-Checked 'npx' @('ng', 'build') $Frontend
        Write-Host 'Built into frontend\dist\frontend\browser. With the API server running, open http://127.0.0.1:8400/' -ForegroundColor Green
    }

    'dist' {
        Invoke-Checked (Get-Php) @((Join-Path $Root 'scripts\build-dist.php')) $Root
    }
}
