# Stops the main application's queue worker cleanly: the supervisor (scripts\Start-QueueWorker.ps1)
# sees the stop file, and "queue:restart" tells the running worker to finish its current job and exit.
# Queued email that has not been sent stays in the jobs table and goes out when the worker next runs.
#
#   powershell -ExecutionPolicy Bypass -File scripts\Stop-QueueWorker.ps1

$ErrorActionPreference = 'Stop'
$project = Split-Path $PSScriptRoot -Parent
$php = 'C:\xampp\php\php.exe'
if (-not (Test-Path $php)) { $php = 'php' }

New-Item -ItemType File -Force -Path (Join-Path $project 'storage\framework\queue-worker.stop') | Out-Null
Set-Location $project
& $php artisan queue:restart

$deadline = (Get-Date).AddSeconds(75)
do {
    $workers = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object { $_.CommandLine -like '*queue:work*' -and $_.CommandLine -like '*--queue=default*' })
    if ($workers.Count -eq 0) { Write-Host 'Queue worker stopped.'; exit 0 }
    Start-Sleep -Seconds 2
} while ((Get-Date) -lt $deadline)

Write-Host "The worker (process $($workers.ProcessId -join ', ')) is still finishing a job. It will exit by itself; nothing new will start."
