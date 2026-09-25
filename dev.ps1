<#
.SYNOPSIS
  Everyday commands for running Hoyo Analyzer on Windows.

.DESCRIPTION
  setup         Install dependencies, create the SQLite database and download the game catalog.
  start         Open the API server, queue worker, scheduler and Angular dev server in separate windows.
  sync          Fetch builds now (sources synced in the last 20 hours are skipped unless -Force).
  test          Run the backend (PHPUnit) and frontend (Vitest) tests.
  build         Build the frontend into backend/public/app (then "php artisan serve" alone serves everything).
  install-task  Register a Windows scheduled task that syncs builds every day, even without "start".
  remove-task   Remove that scheduled task.

  PHP 8.4+ is required. When "php" on PATH is older, set HOYO_PHP to the php.exe to use, e.g. in
  dev.local.ps1 (not committed):  $env:HOYO_PHP = 'C:\path\to\php-8.5\php.exe'

.EXAMPLE
  .\dev.ps1 setup
  .\dev.ps1 start
  .\dev.ps1 sync -Force
#>
param(
    [Parameter(Position = 0)]
    [ValidateSet('setup', 'start', 'sync', 'test', 'build', 'install-task', 'remove-task')]
    [string]$Command = 'start',
    [switch]$Force
)

$ErrorActionPreference = 'Stop'
$Root = $PSScriptRoot
$Backend = Join-Path $Root 'backend'
$Frontend = Join-Path $Root 'frontend'
$TaskName = 'Hoyo Analyzer daily build sync'

$localConfig = Join-Path $Root 'dev.local.ps1'
if (Test-Path $localConfig) {
    . $localConfig
}

function Get-Php {
    $candidates = @()
    if ($env:HOYO_PHP) { $candidates += $env:HOYO_PHP }
    $onPath = Get-Command php -ErrorAction SilentlyContinue
    if ($onPath) { $candidates += $onPath.Source }

    foreach ($php in $candidates) {
        $version = & $php -r 'echo PHP_VERSION_ID;' 2>$null
        if ($LASTEXITCODE -eq 0 -and [int]$version -ge 80400) {
            return $php
        }
    }
    throw "PHP 8.4 or newer was not found. Put `$env:HOYO_PHP = 'C:\path\to\php.exe' into dev.local.ps1."
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

function Get-Composer([string]$Php) {
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
        $composer = Get-Composer $php
        Write-Host "Using $php"

        Invoke-Checked $php @($composer, 'install', '--no-interaction') $Backend
        if (-not (Test-Path (Join-Path $Backend '.env'))) {
            Copy-Item (Join-Path $Backend '.env.example') (Join-Path $Backend '.env')
            Invoke-Checked $php @('artisan', 'key:generate') $Backend
        }
        $database = Join-Path $Backend 'database\database.sqlite'
        if (-not (Test-Path $database)) { New-Item -ItemType File $database | Out-Null }
        Invoke-Checked $php @('artisan', 'migrate', '--force') $Backend
        Invoke-Checked $php @('artisan', 'catalog:sync') $Backend

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
        Write-Host 'Done. Next: .\dev.ps1 start   (first build sync: .\dev.ps1 sync)' -ForegroundColor Green
    }

    'start' {
        $php = Get-Php
        $windows = @(
            @{ Title = 'API (php artisan serve)'; Dir = $Backend; Cmd = "& '$php' artisan serve --host=127.0.0.1 --port=8000" },
            @{ Title = 'Queue worker'; Dir = $Backend; Cmd = "& '$php' artisan queue:work --timeout=3300" },
            @{ Title = 'Scheduler'; Dir = $Backend; Cmd = "& '$php' artisan schedule:work" },
            @{ Title = 'Angular dev server'; Dir = $Frontend; Cmd = 'npx ng serve --open' }
        )
        foreach ($window in $windows) {
            $script = "`$Host.UI.RawUI.WindowTitle = 'Hoyo Analyzer - $($window.Title)'; Set-Location '$($window.Dir)'; $($window.Cmd)"
            Start-Process powershell -ArgumentList '-NoExit', '-Command', $script | Out-Null
        }
        Write-Host 'Started 4 windows. The app opens at http://localhost:4200 (close the windows to stop).' -ForegroundColor Green
    }

    'sync' {
        $php = Get-Php
        $arguments = @('artisan', 'builds:sync')
        if ($Force) { $arguments += '--force' }
        Invoke-Checked $php $arguments $Backend
    }

    'test' {
        $php = Get-Php
        Invoke-Checked $php @('artisan', 'test') $Backend
        Invoke-Checked 'npx' @('ng', 'test', '--watch=false') $Frontend
    }

    'build' {
        Invoke-Checked 'npx' @('ng', 'build', '--configuration', 'backend') $Frontend
        Write-Host 'Built into backend\public\app. Run "php artisan serve" and open http://127.0.0.1:8000/app/' -ForegroundColor Green
    }

    'install-task' {
        $php = Get-Php
        $action = New-ScheduledTaskAction -Execute 'powershell.exe' -WorkingDirectory $Backend `
            -Argument "-NoProfile -WindowStyle Hidden -Command `"& '$php' artisan catalog:sync; & '$php' artisan builds:sync`""
        $trigger = New-ScheduledTaskTrigger -Daily -At '04:30'
        # Run as soon as possible when the PC was off at 04:30.
        $settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 3)
        Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Force | Out-Null
        Write-Host "Registered '$TaskName' (daily at 04:30, catches up after the PC was off)." -ForegroundColor Green
    }

    'remove-task' {
        Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
        Write-Host "Removed '$TaskName'."
    }
}
