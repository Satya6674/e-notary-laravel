# Keeps ONE queue worker running for the main application (queue "default"), restarting it when it
# exits, so queued email (security alerts, application, appointment, support and staff notices) is
# sent within seconds. The registration code email never waits for it (the request sends it); the
# Forgot Password code is a queued job. Emergency stop for all email: php artisan enotary:mail-stop on.
#
# Started at log-on by the Startup-folder entry that scripts\Install-QueueWorkerStartup.ps1 creates,
# or by hand:   powershell -ExecutionPolicy Bypass -File scripts\Start-QueueWorker.ps1
# Stopped by:   powershell -ExecutionPolicy Bypass -File scripts\Stop-QueueWorker.ps1
#
# Each worker run handles jobs for at most an hour, then exits and is started again (memory hygiene).
# A job is tried 3 times with the notification's own back-off (30 s, 2 min, 10 min); a job that still
# fails goes to failed_jobs and its delivery record says FAILED. MAIL_TIMEOUT (15 s) stops a hung SMTP
# connection outliving the queue's retry window, which Windows' missing pcntl would otherwise allow.
# The worker's output (job class names and outcomes, never message contents) goes to
# storage\logs\queue-worker.log.

$ErrorActionPreference = 'Stop'
$project = Split-Path $PSScriptRoot -Parent
$log = Join-Path $project 'storage\logs\queue-worker.log'
$stopFile = Join-Path $project 'storage\framework\queue-worker.stop'
$php = 'C:\xampp\php\php.exe'
if (-not (Test-Path $php)) { $php = 'php' }

# One supervisor per Windows session.
$created = $false
$mutex = [System.Threading.Mutex]::new($true, 'Local\ENotaryQueueWorker', [ref]$created)
if (-not $created) {
    Write-Host 'The E-Notary queue worker is already running in this session.'
    exit 0
}

# Never two workers on the same queue: that could send one email twice.
$others = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object { $_.CommandLine -like '*queue:work*' -and $_.CommandLine -like '*--queue=default*' })
if ($others.Count -gt 0) {
    Write-Host "A worker for the default queue is already running (process $($others.ProcessId -join ', ')). Not starting another."
    exit 0
}

if (Test-Path $stopFile) { Remove-Item $stopFile -Force }
Set-Location $project

function Write-Log([string]$Line) {
    Add-Content -Path $log -Value ("[{0}] {1}" -f (Get-Date -Format 's'), $Line) -Encoding utf8
}

Write-Log 'Supervisor started.'
try {
    while (-not (Test-Path $stopFile)) {
        $started = Get-Date
        # Out-File -Encoding utf8, not *>>: Windows PowerShell 5.1 would write UTF-16 into the log.
        & $php artisan queue:work database --queue=default --name=enotary-main-worker --sleep=3 --tries=3 --timeout=60 --max-time=3600 --memory=192 2>&1 |
            ForEach-Object { "$_" } | Out-File -FilePath $log -Append -Encoding utf8
        $code = $LASTEXITCODE
        Write-Log "Worker exited with code $code."

        if (Test-Path $stopFile) { break }

        # A quick exit usually means the database (XAMPP MySQL) is not up yet: wait longer before retrying.
        $ranFor = ((Get-Date) - $started).TotalSeconds
        Start-Sleep -Seconds ($(if ($ranFor -lt 30) { 30 } else { 3 }))
    }
} finally {
    Write-Log 'Supervisor stopped.'
    if (Test-Path $stopFile) { Remove-Item $stopFile -Force -ErrorAction SilentlyContinue }
    $mutex.ReleaseMutex()
}
