# Makes the main application's queue worker start at Windows log-on, for this user only, by placing a
# small launcher in the user's Startup folder. No administrator rights, no scheduled task, no service.
#
#   powershell -ExecutionPolicy Bypass -File scripts\Install-QueueWorkerStartup.ps1           (install)
#   powershell -ExecutionPolicy Bypass -File scripts\Install-QueueWorkerStartup.ps1 -Remove   (remove)
#
# The launcher runs scripts\Start-QueueWorker.ps1 in a hidden window. XAMPP MySQL must be running for
# the worker to do anything; until it is, the supervisor simply waits and tries again.

param([switch]$Remove)

$ErrorActionPreference = 'Stop'
$project = Split-Path $PSScriptRoot -Parent
$startup = [Environment]::GetFolderPath('Startup')
$launcher = Join-Path $startup 'E-Notary Queue Worker.cmd'

if ($Remove) {
    if (Test-Path $launcher) { Remove-Item $launcher -Force; Write-Host "Removed $launcher" } else { Write-Host 'Nothing to remove.' }
    exit 0
}

$script = Join-Path $project 'scripts\Start-QueueWorker.ps1'
$content = "@echo off`r`nrem E-Notary: keeps the main application's email queue worker running. Remove with scripts\Install-QueueWorkerStartup.ps1 -Remove`r`nstart `"`" /min powershell.exe -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$script`"`r`n"
Set-Content -Path $launcher -Value $content -Encoding ascii -NoNewline
Write-Host "Installed $launcher"
