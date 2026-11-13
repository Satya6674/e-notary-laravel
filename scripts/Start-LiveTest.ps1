# Runs the app on http://127.0.0.1:8001 with REAL email and SMS, against the scratch database.
# Every real message can reach only the addresses in the allowlist file below, which
# lives outside the project and is never committed. It refuses to start without one.
#
#   powershell -ExecutionPolicy Bypass -File scripts\Start-LiveTest.ps1          the web server
#   powershell -ExecutionPolicy Bypass -File scripts\Start-LiveTest.ps1 -Worker  the queue worker
#   powershell -ExecutionPolicy Bypass -File scripts\Start-LiveTest.ps1 -NoSms   the web server, SMS off
#
# The worker stops itself after an hour and tries each email at most 3 times.

param([switch]$Worker, [switch]$NoSms)

$ErrorActionPreference = 'Stop'

$root = Join-Path $env:USERPROFILE 'E-Notary-backups'
$allow = Join-Path $root 'live-test-allowlist.txt'

if (-not (Test-Path $allow)) {
    throw "Allowlist not found at $allow. It needs two lines: email=... and mobile=..."
}

$lines = Get-Content $allow
$email = ($lines | Where-Object { $_ -like 'email=*' }) -replace '^email=', ''
$mobile = ($lines | Where-Object { $_ -like 'mobile=*' }) -replace '^mobile=', ''

if (-not $email -or -not $mobile) { throw 'The allowlist needs both an email and a mobile line.' }

$env:OUTBOUND_ALLOW_MAIL = $email
$env:OUTBOUND_ALLOW_SMS = $mobile
if ($NoSms) { $env:MSG91_ENABLED = 'false' }
$env:DB_DATABASE = 'enotary_scratch'
$env:LARAVEL_STORAGE_PATH = Join-Path $root 'preview-storage'
$env:LOG_CHANNEL = 'single'
$env:APP_URL = 'http://127.0.0.1:8001'

$project = Split-Path $PSScriptRoot -Parent

if ($Worker) {
    Set-Location $project
    php artisan queue:work --queue=default --tries=3 --timeout=60 --max-time=3600
    exit $LASTEXITCODE
}

php -S 127.0.0.1:8001 -t (Join-Path $project 'public') (Join-Path $PSScriptRoot 'preview-router.php')
