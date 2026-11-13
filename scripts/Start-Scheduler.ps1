# Keeps ONE Laravel scheduler (php artisan schedule:work) running for the main application, restarting
# it when it exits, so the recurring tasks in routes/console.php run:
#   every minute  scheduler heartbeat (shown on Admin > Security and by enotary:health)
#   hourly        enotary:appointment-reminders (emails reminders for booked appointments)
#   hourly        enotary:record-access-expiry  (audits temporary-access grants that ran out)
#   02:00 IST     enotary:clear-expired-codes   (deletes expired one-time-code rows)
#   02:10 IST     enotary:expire-stale-requests
#   02:20 IST     enotary:clear-stale-drafts    (deletes abandoned drafts)
#   02:30 IST     queue:prune-failed --hours=720
#
# IMPORTANT: these tasks change data and may send email. Start this against enotary_local only after
# the owner has approved it. It is deliberately NOT added to the Startup folder by any script.
#
#   powershell -ExecutionPolicy Bypass -File scripts\Start-Scheduler.ps1
#   powershell -ExecutionPolicy Bypass -File scripts\Stop-Scheduler.ps1
#
# Output (task names and outcomes) goes to storage\logs\scheduler.log.

$ErrorActionPreference = 'Stop'
$project = Split-Path $PSScriptRoot -Parent
$log = Join-Path $project 'storage\logs\scheduler.log'
$stopFile = Join-Path $project 'storage\framework\scheduler.stop'
$php = 'C:\xampp\php\php.exe'
if (-not (Test-Path $php)) { $php = 'php' }

# One supervisor per Windows session.
$created = $false
$mutex = [System.Threading.Mutex]::new($true, 'Local\ENotaryScheduler', [ref]$created)
if (-not $created) {
    Write-Host 'The E-Notary scheduler is already running in this session.'
    exit 0
}

# Never two schedulers: tasks would run twice (withoutOverlapping guards most, not all).
$others = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object { $_.CommandLine -like '*schedule:work*' -or $_.CommandLine -like '*schedule:run*' })
if ($others.Count -gt 0) {
    Write-Host "A scheduler is already running (process $($others.ProcessId -join ', ')). Not starting another."
    exit 0
}

if (Test-Path $stopFile) { Remove-Item $stopFile -Force }
Set-Location $project

function Write-Log([string]$Line) {
    Add-Content -Path $log -Value ("[{0}] {1}" -f (Get-Date -Format 's'), $Line) -Encoding utf8
}

Write-Log 'Scheduler supervisor started.'
try {
    while (-not (Test-Path $stopFile)) {
        $started = Get-Date
        & $php artisan schedule:work 2>&1 | ForEach-Object { "$_" } | Out-File -FilePath $log -Append -Encoding utf8
        Write-Log "schedule:work exited with code $LASTEXITCODE."
        if (Test-Path $stopFile) { break }
        $ranFor = ((Get-Date) - $started).TotalSeconds
        Start-Sleep -Seconds ($(if ($ranFor -lt 30) { 30 } else { 3 }))
    }
} finally {
    Write-Log 'Scheduler supervisor stopped.'
    if (Test-Path $stopFile) { Remove-Item $stopFile -Force -ErrorAction SilentlyContinue }
    $mutex.ReleaseMutex()
}
