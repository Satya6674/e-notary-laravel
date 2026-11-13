# Runs a second copy of the app on http://127.0.0.1:8002 with mock SMS, so every OTP flow
# can be tried in a browser with no paid message. Codes appear at http://127.0.0.1:8003.
#
#   powershell -ExecutionPolicy Bypass -File scripts\Start-Otp-Mock.ps1          start
#   powershell -ExecutionPolicy Bypass -File scripts\Start-Otp-Mock.ps1 -Fresh   start from a new copy of the data
#   powershell -ExecutionPolicy Bypass -File scripts\Start-Otp-Mock.ps1 -Stop    stop
#
# It uses enotary_otp_mock, a copy of the scratch database with no queued jobs, so held
# jobs are not copied. There is no MSG91 key here: a made-up key is used, and only the
# local stand-in can be reached. Email goes to a log. .env is not changed.

param([switch]$Fresh, [switch]$Stop)

$ErrorActionPreference = 'Stop'

$project = Split-Path $PSScriptRoot -Parent
$backups = Join-Path $env:USERPROFILE 'E-Notary-backups'
$runDir = Join-Path $backups 'otp-mock'
$stateFile = Join-Path $runDir 'run.json'
$mysqlBin = 'C:\xampp\mysql\bin'
$database = 'enotary_otp_mock'
$numbers = '0000000901,0000000902,0000000903,0000000904'

$null = New-Item -ItemType Directory -Force -Path $runDir

function Stop-Mock {
    if (-not (Test-Path $stateFile)) { return }

    $state = Get-Content $stateFile -Raw | ConvertFrom-Json
    foreach ($item in @(@($state.app, '*127.0.0.1:8002*'), @($state.stub, '*127.0.0.1:8003*'))) {
        $process = Get-CimInstance Win32_Process -Filter "ProcessId=$($item[0])"
        if ($process -and $process.CommandLine -like $item[1]) { Stop-Process -Id $item[0] -Force -ErrorAction SilentlyContinue }
    }
    Remove-Item $stateFile -ErrorAction SilentlyContinue
}

if ($Stop) {
    Stop-Mock
    Write-Host 'Mock SMS site stopped.' -ForegroundColor Green
    exit 0
}

if (Test-Path $stateFile) {
    $state = Get-Content $stateFile -Raw | ConvertFrom-Json
    if (Get-CimInstance Win32_Process -Filter "ProcessId=$($state.app)") {
        Write-Host 'Already running at http://127.0.0.1:8002. Codes: http://127.0.0.1:8003' -ForegroundColor Green
        exit 0
    }
    Remove-Item $stateFile
}

$sql = { param($Statement) & "$mysqlBin\mysql.exe" '--user=root' '--host=127.0.0.1' -N -e $Statement }
$exists = & $sql "show databases like '$database'"

if ($Fresh -or -not $exists) {
    Write-Host 'Copying the scratch database, without queued jobs...'
    $dump = Join-Path $runDir 'copy.sql'
    $jobs = Join-Path $runDir 'jobs.sql'
    cmd /c "`"$mysqlBin\mysqldump.exe`" --user=root --host=127.0.0.1 --single-transaction --ignore-table=enotary_scratch.jobs enotary_scratch > `"$dump`""
    if ($LASTEXITCODE -ne 0) { throw 'Could not copy the scratch database.' }
    cmd /c "`"$mysqlBin\mysqldump.exe`" --user=root --host=127.0.0.1 --no-data enotary_scratch jobs > `"$jobs`""
    if ($LASTEXITCODE -ne 0) { throw 'Could not copy the jobs table.' }

    & $sql "drop database if exists $database; create database $database character set utf8mb4 collate utf8mb4_unicode_ci"
    foreach ($file in @($dump, $jobs)) {
        cmd /c "`"$mysqlBin\mysql.exe`" --user=root --host=127.0.0.1 $database < `"$file`""
        if ($LASTEXITCODE -ne 0) { throw 'Could not load the copy.' }
    }
    Remove-Item $dump, $jobs

    # In this copy only: a Citizen who has reached the mobile step, and a clean code list.
    & $sql "update $database.users set email_verified_at = now(), mobile_verified_at = null where id = 33; delete from $database.otp_verifications; delete from $database.cache"
}

$env:DB_DATABASE = $database
$env:LARAVEL_STORAGE_PATH = Join-Path $backups 'preview-storage'
$env:MAIL_MAILER = 'log'
$env:LOG_CHANNEL = 'single'
$env:APP_URL = 'http://127.0.0.1:8002'
$env:QUEUE_CONNECTION = 'sync'
$env:MSG91_AUTH_KEY = 'mock-key-not-a-real-key'
$env:MSG91_TEMPLATE_ID = 'mock-template'
$env:MSG91_ENDPOINT = 'http://127.0.0.1:8003/otp'
$env:MSG91_PREFLIGHT = 'false'
# The mock SMS site tests the SMS path, so the email fallback stays off here.
$env:MOBILE_EMAIL_FALLBACK = 'false'
$env:OUTBOUND_ALLOW_SMS = $numbers

Set-Location $project

$stub = Start-Process -FilePath 'php' -ArgumentList @('-S', '127.0.0.1:8003', 'scripts\otp-stub.php') -WorkingDirectory $project -WindowStyle Hidden `
    -RedirectStandardOutput "$runDir\stub.out" -RedirectStandardError "$runDir\stub.err" -PassThru
$app = Start-Process -FilePath 'php' -ArgumentList @('-S', '127.0.0.1:8002', '-t', 'public', 'scripts\preview-router.php') -WorkingDirectory $project -WindowStyle Hidden `
    -RedirectStandardOutput "$runDir\app.out" -RedirectStandardError "$runDir\app.err" -PassThru

@{ app = $app.Id; stub = $stub.Id; started = (Get-Date).ToString('s') } | ConvertTo-Json | Set-Content $stateFile

foreach ($attempt in 1..20) {
    Start-Sleep -Seconds 1
    try {
        if ((Invoke-WebRequest 'http://127.0.0.1:8002/login' -UseBasicParsing -TimeoutSec 5).StatusCode -eq 200) {
            Write-Host 'Mock SMS site: http://127.0.0.1:8002  Codes: http://127.0.0.1:8003' -ForegroundColor Green
            exit 0
        }
    } catch { }
}

Stop-Mock
throw 'The mock site did not answer.'
