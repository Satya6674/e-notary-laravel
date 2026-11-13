# Runs the demonstration site on http://127.0.0.1:8000 against enotary_local WITHOUT sending
# anything: email is written to the private log (links redacted), queued work runs at once, SMS
# is off. No queue worker and no scheduler are started. .env is not changed.
#
#   powershell -ExecutionPolicy Bypass -File scripts\Start-Demo-Preview.ps1
#
# For real Gmail delivery to approved addresses use scripts\Start-Local-Gmail.ps1 instead
# (that one also starts the queue worker and the scheduler). Ctrl+C stops this server.

$ErrorActionPreference = 'Stop'

$project = Split-Path $PSScriptRoot -Parent
$storage = Join-Path $env:USERPROFILE 'E-Notary-backups\preview-storage'

if (-not (Test-Path (Join-Path $storage 'app\private'))) { throw "Storage not found at $storage" }
if (Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue | Where-Object { $_.OwningProcess -ne 0 }) {
    throw 'Port 8000 is in use. Stop the other server first.'
}

$env:DB_DATABASE = 'enotary_local'
$env:LARAVEL_STORAGE_PATH = $storage
$env:MAIL_MAILER = 'log'
$env:QUEUE_CONNECTION = 'sync'
$env:MSG91_ENABLED = 'false'
$env:LOG_CHANNEL = 'single'
$env:APP_URL = 'http://127.0.0.1:8000'

Write-Host 'E-Notary demo preview: http://127.0.0.1:8000  (no email or SMS is sent; Ctrl+C to stop)' -ForegroundColor Green
Write-Host 'Check http://127.0.0.1:8000/__preview-gate shows "database":"enotary_local" before signing in.'
php -S 127.0.0.1:8000 -t (Join-Path $project 'public') (Join-Path $PSScriptRoot 'preview-router.php')
