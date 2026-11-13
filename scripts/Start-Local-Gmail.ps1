# Runs the app on http://127.0.0.1:8000 with real Gmail email and its queue worker, against the
# disposable database enotary_gmail_test (made by scripts\New-LocalGmailDatabase.ps1), using the
# Gmail login already in .env. It never runs on enotary_local or any other permanent database.
#
# SMTP is switched on for the processes this script starts only. .env must keep MAIL_MAILER=log, so
# Apache and a plain `php artisan serve` stay in Safe Preview; the readiness check refuses otherwise.
#
#   powershell -ExecutionPolicy Bypass -File scripts\Start-Local-Gmail.ps1              start
#   powershell -ExecutionPolicy Bypass -File scripts\Start-Local-Gmail.ps1 -Check       check, start nothing
#   powershell -ExecutionPolicy Bypass -File scripts\Start-Local-Gmail.ps1 -TestUser 29 queue one test email, after the same check
#   powershell -ExecutionPolicy Bypass -File scripts\Stop-Local-Gmail.ps1               stop, from another window
#
# Paid SMS stays off unless named here, and then reaches only the number in
# live-test-allowlist.txt, for at most one message per approval id, even after a restart:
#   powershell -ExecutionPolicy Bypass -File scripts\Start-Local-Gmail.ps1 -SmsApproval 2026-11-01-owner
#
# Ctrl+C or closing this window stops the server, worker and scheduler, because a Windows
# job object ties them to this script. Nothing is installed as a service.
#
# Real mail reaches only the addresses in local-gmail-recipients.txt, which lives outside
# the project and is never committed. An address that belongs to an Admin account, or to any
# account in a permanent database, is refused as well unless it is confirmed for this run:
#   ... Start-Local-Gmail.ps1 -ConfirmRecipient owner.test@example.com
# The settings below apply only to the
# processes this script starts, so .env and the site Apache serves are unchanged.

param([switch]$Check, [int]$TestUser = 0, [string]$SmsApproval = '', [string[]]$ConfirmRecipient = @())

$ErrorActionPreference = 'Stop'

$project = Split-Path $PSScriptRoot -Parent
$backups = Join-Path $env:USERPROFILE 'E-Notary-backups'
$recipients = Join-Path $backups 'local-gmail-recipients.txt'
$runDir = Join-Path $backups 'local-gmail'
$stateFile = Join-Path $runDir 'run.json'
$url = 'http://127.0.0.1:8000'
$workerName = 'enotary-local-gmail'

function Stop-WithError([string]$Message) {
    Write-Host "NOT STARTED: $Message" -ForegroundColor Red
    exit 1
}

function Get-Readiness([switch]$Smtp) {
    $arguments = @('artisan', 'enotary:local-gmail-check', '--json')
    if ($Smtp) { $arguments += '--smtp' }

    $ErrorActionPreference = 'Continue'
    $output = & php @arguments 2>$null
    $line = @($output) | Where-Object { "$_".StartsWith('{') } | Select-Object -Last 1
    if (-not $line) { return $null }

    return $line | ConvertFrom-Json
}

function Show-Readiness($Report) {
    Write-Host "  Mail mode:          $($Report.mode)"
    Write-Host "  SMS:                $($Report.sms)"
    Write-Host "  Database:           $($Report.database)"
    Write-Host "  Gmail login:        $($Report.smtp)"
    Write-Host "  Approved recipients:"
    foreach ($r in $Report.recipients) { Write-Host "    $($r.address)  $($r.account)" }
    Write-Host "  Waiting to send:    $($Report.waiting)"
    Write-Host "  Failed jobs:        $($Report.failed)"
    Write-Host "  Held on other queues, never touched here: $($Report.held)"
    foreach ($f in $Report.failures) { Write-Host "  FAILED: $f" -ForegroundColor Red }
}

# Binding is the only reliable test. A stopped server's socket can take a moment to clear.
function Test-PortFree([int]$Port) {
    foreach ($attempt in 1..5) {
        $listener = New-Object System.Net.Sockets.TcpListener([System.Net.IPAddress]::Loopback, $Port)
        try {
            $listener.Start()
            $listener.Stop()
            return $true
        } catch {
            Start-Sleep -Seconds 1
        }
    }

    return $false
}

function Start-Managed([string[]]$Arguments, [string]$Log) {
    $process = Start-Process -FilePath 'php' -ArgumentList $Arguments -WorkingDirectory $project -WindowStyle Hidden `
        -RedirectStandardOutput "$Log.out" -RedirectStandardError "$Log.err" -PassThru
    $null = $process.Handle # Keeps the handle, so the exit state stays readable.
    [LocalGmail.ManagedJob]::Add($process.Handle)

    return $process
}

# 1-3. Tools, project and settings. Nothing below prints a secret.
if (-not (Get-Command php -ErrorAction SilentlyContinue)) { Stop-WithError 'PHP is not on PATH.' }
if (-not (Get-Command composer -ErrorAction SilentlyContinue)) { Write-Host 'Composer is not on PATH. It is not needed to run the app.' -ForegroundColor Yellow }
if (-not (Test-Path (Join-Path $project 'artisan'))) { Stop-WithError "No Laravel project at $project." }
if (-not (Test-Path (Join-Path $project 'vendor\autoload.php'))) { Stop-WithError 'Dependencies are missing. Run composer install.' }
if (-not (Test-Path (Join-Path $project '.env'))) { Stop-WithError '.env is missing.' }
if (-not (Test-Path $recipients)) { Stop-WithError "Recipient list not found at $recipients. See docs\email\PERMANENT_LOCAL_EMAIL_SETUP.md." }

$approved = @(Get-Content $recipients | ForEach-Object { $_.Trim() } | Where-Object { $_ -and -not $_.StartsWith('#') })
if ($approved.Count -eq 0) { Stop-WithError 'The recipient list has no addresses.' }

$confirmed = @($ConfirmRecipient | ForEach-Object { $_ -split ',' } | ForEach-Object { $_.Trim().ToLower() } | Where-Object { $_ })
foreach ($address in $confirmed) {
    if ($approved -notcontains $address) { Stop-WithError "A confirmed recipient must also be in $recipients." }
}

# SMTP for these processes only. .env keeps MAIL_MAILER=log, which the readiness check verifies.
$env:MAIL_MAILER = 'smtp'
$env:MAIL_MODE = 'local_gmail'
$env:OUTBOUND_ALLOW_MAIL = $approved -join ','
# Never inherited from the calling window: only this run's -ConfirmRecipient counts.
Remove-Item Env:OUTBOUND_CONFIRM_MAIL -ErrorAction SilentlyContinue
if ($confirmed.Count -gt 0) { $env:OUTBOUND_CONFIRM_MAIL = $confirmed -join ',' }
if ($SmsApproval) {
    $smsList = Join-Path $backups 'live-test-allowlist.txt'
    if (-not (Test-Path $smsList)) { Stop-WithError "SMS number list not found at $smsList." }
    $number = ((Get-Content $smsList | Where-Object { $_ -like 'mobile=*' }) -replace '^mobile=', '').Trim()
    if (-not $number) { Stop-WithError 'The SMS number list has no mobile line.' }
    $env:OUTBOUND_ALLOW_SMS = $number
    $env:MSG91_SEND_BUDGET = '1'
    $env:MSG91_BUDGET_ID = $SmsApproval
} else {
    $env:MSG91_ENABLED = 'false'
}
# The disposable test database. The app sends nothing in this mode from any other database.
$env:DB_DATABASE = 'enotary_gmail_test'
$env:LARAVEL_STORAGE_PATH = Join-Path $backups 'preview-storage'
$env:LOG_CHANNEL = 'single'
$env:APP_URL = $url
$env:QUEUE_CONNECTION = 'database'
$env:DB_QUEUE = 'local_gmail'
$env:MAIL_TIMEOUT = '20'

Set-Location $project

# 6. A cached configuration would ignore every setting above. Removing it loses nothing.
if (Test-Path 'bootstrap\cache\config.php') {
    Write-Host 'Clearing a stale configuration cache.'
    & php artisan config:clear | Out-Null
}

# 4-5. Database and mail, proved by the app itself. Logs in to Gmail and sends nothing.
# Every path passes this first, including -TestUser.
Write-Host 'Checking database, storage and Gmail settings...'
$ready = Get-Readiness -Smtp
if (-not $ready) { Stop-WithError 'The readiness check did not run. The database may be stopped: is MySQL running in XAMPP?' }
Show-Readiness $ready
if (-not $ready.ok) { Stop-WithError 'Fix the items marked FAILED.' }
if ($Check) { Write-Host 'Ready. Nothing was started.' -ForegroundColor Green; exit 0 }

if ($TestUser -gt 0) {
    & php artisan enotary:local-gmail-test $TestUser
    exit $LASTEXITCODE
}

# 9. Never a second copy.
$workers = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object { $_.CommandLine -like '*queue:work*' })
if (Test-Path $stateFile) {
    $previous = Get-Content $stateFile -Raw | ConvertFrom-Json
    $owner = Get-CimInstance Win32_Process -Filter "ProcessId=$($previous.script)"
    if ($owner -and $owner.CommandLine -like '*Start-Local-Gmail*') {
        Write-Host "Already running at $url. To stop it, run scripts\Stop-Local-Gmail.ps1." -ForegroundColor Green
        exit 0
    }
    Remove-Item $stateFile
}
if ($workers.Count -gt 0) {
    Stop-WithError "A queue worker is already running (process $($workers.ProcessId -join ', ')). Stop it first, so that mail is not processed twice."
}
if (-not (Test-PortFree 8000)) {
    Stop-WithError 'Port 8000 is in use. Stop whatever is using it, because email links point to this port.'
}

Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;

namespace LocalGmail {
    // Windows closes the job when this script ends, however it ends, and that ends its members.
    public static class ManagedJob {
        [StructLayout(LayoutKind.Sequential)]
        struct BasicLimits { public long a, b; public uint Flags; public UIntPtr c, d; public uint e; public UIntPtr f; public uint g, h; }
        [StructLayout(LayoutKind.Sequential)]
        struct IoCounters { public ulong a, b, c, d, e, f; }
        [StructLayout(LayoutKind.Sequential)]
        struct ExtendedLimits { public BasicLimits Basic; public IoCounters Io; public UIntPtr a, b, c, d; }

        [DllImport("kernel32.dll", CharSet = CharSet.Unicode)] static extern IntPtr CreateJobObject(IntPtr attributes, string name);
        [DllImport("kernel32.dll")] static extern bool SetInformationJobObject(IntPtr job, int infoClass, ref ExtendedLimits info, uint length);
        [DllImport("kernel32.dll")] static extern bool AssignProcessToJobObject(IntPtr job, IntPtr process);

        static IntPtr job = IntPtr.Zero;

        public static void Add(IntPtr process) {
            if (job == IntPtr.Zero) {
                job = CreateJobObject(IntPtr.Zero, null);
                var limits = new ExtendedLimits();
                limits.Basic.Flags = 0x2000; // JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE
                if (!SetInformationJobObject(job, 9, ref limits, (uint)Marshal.SizeOf(typeof(ExtendedLimits)))) {
                    throw new InvalidOperationException("Could not create the process job.");
                }
            }
            if (!AssignProcessToJobObject(job, process)) {
                throw new InvalidOperationException("Could not attach a process to the job.");
            }
        }
    }
}
'@

$null = New-Item -ItemType Directory -Force -Path $runDir

# Request lines can hold signed links, so these logs stay in the private folder and
# are replaced at each start. They are not printed.
$serverLog = Join-Path $runDir 'server'
$workerLog = Join-Path $runDir 'worker'
$workerArguments = @('artisan', 'queue:work', 'database', '--queue=local_gmail', "--name=$workerName",
    '--tries=3', '--backoff=30,120,600', '--timeout=60', '--sleep=3', '--max-time=3600')

$server = $null
$worker = $null
$scheduler = $null

try {
    # 7-8. Separate processes, both owned by this script.
    $server = Start-Managed @('-S', '127.0.0.1:8000', '-t', 'public', 'scripts\preview-router.php') $serverLog
    $worker = Start-Managed $workerArguments $workerLog
    $scheduler = Start-Managed @('artisan', 'schedule:work') (Join-Path $runDir 'scheduler')
    $workerStarted = Get-Date
    $quickExits = 0

    @{ script = $PID; server = $server.Id; worker = $worker.Id; scheduler = $scheduler.Id; started = (Get-Date).ToString('s') } | ConvertTo-Json | Set-Content $stateFile

    # 10. The app must answer, and the worker must have reported in through the database.
    $serving = $false
    $beating = $false
    foreach ($attempt in 1..20) {
        Start-Sleep -Seconds 1
        if ($server.HasExited) { Stop-WithError "The web server stopped during startup. See $serverLog.err." }
        if ($worker.HasExited) { Stop-WithError "The worker stopped during startup. See $workerLog.err." }

        if (-not $serving) {
            try {
                $serving = (Invoke-WebRequest "$url/login" -UseBasicParsing -TimeoutSec 5).StatusCode -eq 200
            } catch { $serving = $false }
        }
        if ($serving -and -not $beating) {
            $state = Get-Readiness
            $beating = $state -and $state.worker
        }
        if ($serving -and $beating) { break }
    }

    if (-not $serving) { Stop-WithError "The app did not answer at $url." }
    if (-not $beating) { Stop-WithError 'The worker did not report in.' }

    # 11-12.
    Write-Host ''
    Write-Host "App:    $url" -ForegroundColor Green
    Write-Host "Email:  OPERATIONAL. Web server process $($server.Id), worker process $($worker.Id), scheduler process $($scheduler.Id)." -ForegroundColor Green
    Write-Host "SMS:    $($ready.sms)"
    Write-Host 'Queued email is sent by the worker. Check results under Admin, Delivery.'
    Write-Host 'Press Ctrl+C here to stop the server, worker and scheduler.'

    while ($true) {
        Start-Sleep -Seconds 5

        if ($server.HasExited) {
            Write-Host 'The web server stopped, so the worker is being stopped too.' -ForegroundColor Red
            exit 1
        }

        if ($scheduler.HasExited) {
            $scheduler = Start-Managed @('artisan', 'schedule:work') (Join-Path $runDir 'scheduler')
            @{ script = $PID; server = $server.Id; worker = $worker.Id; scheduler = $scheduler.Id; started = (Get-Date).ToString('s') } | ConvertTo-Json | Set-Content $stateFile
            Write-Host "Scheduler restarted as process $($scheduler.Id)."
        }

        # The worker leaves on its own each hour. A run of quick exits means it cannot run.
        if ($worker.HasExited) {
            $quickExits = if (((Get-Date) - $workerStarted).TotalSeconds -lt 60) { $quickExits + 1 } else { 0 }

            if ($quickExits -ge 3) {
                Write-Host "Email:  STOPPED. The worker failed 3 times in a row, and was not restarted. See $workerLog.err." -ForegroundColor Red
                exit 1
            }

            $worker = Start-Managed $workerArguments $workerLog
            $workerStarted = Get-Date
            @{ script = $PID; server = $server.Id; worker = $worker.Id; scheduler = $scheduler.Id; started = (Get-Date).ToString('s') } | ConvertTo-Json | Set-Content $stateFile
            Write-Host "Worker restarted as process $($worker.Id)."
        }
    }
} finally {
    foreach ($process in @($scheduler, $worker, $server)) {
        if ($process -and -not $process.HasExited) { Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue }
    }
    if (Test-Path $stateFile) { Remove-Item $stateFile -ErrorAction SilentlyContinue }
    Write-Host 'Server, worker and scheduler stopped.'
}
