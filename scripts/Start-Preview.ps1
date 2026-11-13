# Runs the app on http://127.0.0.1:8001 against the scratch database, without touching
# the real database, real documents or .env. Settings apply to this process only.
# Email goes to the preview log, and MSG91_ENABLED=false removes the SMS key.
#
#   powershell -ExecutionPolicy Bypass -File scripts\Start-Preview.ps1
#
# This calls PHP's server through preview-router.php: "artisan serve" does not pass
# LARAVEL_STORAGE_PATH on, and uploads would then land in the real storage folder.

$ErrorActionPreference = 'Stop'

$storage = Join-Path $env:USERPROFILE 'E-Notary-backups\preview-storage'

if (-not (Test-Path (Join-Path $storage 'app\private'))) {
    throw "Preview storage not found at $storage"
}

$env:DB_DATABASE = 'enotary_scratch'
$env:LARAVEL_STORAGE_PATH = $storage
$env:MAIL_MAILER = 'log'
$env:MSG91_ENABLED = 'false'
$env:LOG_CHANNEL = 'single'
$env:APP_URL = 'http://127.0.0.1:8001'

$project = Split-Path $PSScriptRoot -Parent
Write-Host 'Preview: http://127.0.0.1:8001  (Ctrl+C to stop)'
php -S 127.0.0.1:8001 -t (Join-Path $project 'public') (Join-Path $PSScriptRoot 'preview-router.php')
