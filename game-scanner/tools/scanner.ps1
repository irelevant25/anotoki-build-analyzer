# Genshin Impact scanner for builder.anotoki.eu - version {{VERSION}}
#
# Reads your weapons, artifacts and characters from the game and makes a GOOD file to import on
# builder.anotoki.eu/import. Paste this line into PowerShell started as administrator:
#
#   irm https://github.com/irelevant25/anotoki-build-analyzer/releases/download/scanner/scanner.ps1 | iex
#
# What this script does: downloads the scanner ({{ZIP_URL}}, about {{ZIP_MB}} MB: Node.js and the program),
# checks it against its SHA-256, unpacks it into a folder in TEMP, runs it there, and deletes that folder when
# the program ends. The program deletes the GOOD file it made once you have imported it.
# (tools/scanner.ps1 is the template: npm run package writes the address and the SHA-256 in.)

& {
    $ErrorActionPreference = 'Stop'
    $zipUrl = '{{ZIP_URL}}'
    $zipSha256 = '{{ZIP_SHA256}}'
    $dir = Join-Path $env:TEMP 'anotoki-scanner'

    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    if (-not ([Security.Principal.WindowsPrincipal]$identity).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        Write-Host 'This window is not running as administrator.' -ForegroundColor Red
        Write-Host 'The game runs as administrator, so Windows blocks the clicks and key presses of programs that are not.'
        Write-Host 'Close this window, open PowerShell as administrator (right-click the Start button, then "Terminal (Admin)"'
        Write-Host 'or "Windows PowerShell (Admin)") and paste the command again.'
        return
    }

    function Remove-Scanner {
        for ($try = 1; $try -le 5 -and (Test-Path $dir); $try++) {
            Remove-Item -Recurse -Force $dir -ErrorAction SilentlyContinue
            if (Test-Path $dir) { Start-Sleep -Seconds 1 }
        }
    }

    try {
        Remove-Scanner
        New-Item -ItemType Directory -Force $dir | Out-Null
        $zip = Join-Path $dir 'scanner.zip'
        Write-Host 'Downloading the scanner (about {{ZIP_MB}} MB)...'
        [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
        $ProgressPreference = 'SilentlyContinue'
        Invoke-WebRequest -Uri $zipUrl -OutFile $zip -UseBasicParsing
        $hash = (Get-FileHash -Algorithm SHA256 $zip).Hash
        if ($hash -ne $zipSha256) {
            throw "The download does not match the scanner it should be (SHA-256 $hash). A new version may be on its way: paste the command again in a minute."
        }
        Write-Host 'Unpacking...'
        $tar = Join-Path $env:SystemRoot 'System32\tar.exe'
        if (Test-Path $tar) { & $tar -x -f $zip -C $dir } else { Expand-Archive -Path $zip -DestinationPath $dir -Force }
        if (-not (Test-Path (Join-Path $dir 'node.exe'))) { throw 'The scanner could not be unpacked.' }
        Remove-Item $zip
        & (Join-Path $dir 'node.exe') (Join-Path $dir 'app\src\index.js') '--portable' "--work=$(Join-Path $dir 'work')"
    } catch {
        Write-Host $_.Exception.Message -ForegroundColor Red
    } finally {
        Remove-Scanner
        if (Test-Path $dir) { Write-Host "Could not delete $dir - delete it yourself." -ForegroundColor Yellow }
        else { Write-Host 'The scanner is deleted from this computer.' }
    }
}
