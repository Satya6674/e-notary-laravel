# Stops the scheduler started by scripts\Start-Scheduler.ps1: the supervisor sees the stop file and the
# running schedule:work process is ended. A task already running finishes first where it can.
#
#   powershell -ExecutionPolicy Bypass -File scripts\Stop-Scheduler.ps1

$ErrorActionPreference = 'Stop'
$project = Split-Path $PSScriptRoot -Parent

New-Item -ItemType File -Force -Path (Join-Path $project 'storage\framework\scheduler.stop') | Out-Null

$procs = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object { $_.CommandLine -like '*schedule:work*' })
foreach ($p in $procs) { Stop-Process -Id $p.ProcessId -ErrorAction SilentlyContinue }

Start-Sleep -Seconds 2
$left = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object { $_.CommandLine -like '*schedule:work*' })
if ($left.Count -eq 0) { Write-Host 'Scheduler stopped.' } else { Write-Host "Scheduler process $($left.ProcessId -join ', ') is still exiting." }
