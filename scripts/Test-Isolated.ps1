# Runs the automated tests with their own storage folder, so nothing can touch real
# documents or logs. tests/TestCase.php refuses to run unless the database is in-memory
# SQLite and mail, queue, session, cache and SMS are all fakes.
#
#   powershell -ExecutionPolicy Bypass -File scripts\Test-Isolated.ps1 [phpunit arguments]

$ErrorActionPreference = 'Stop'

$storage = Join-Path $env:USERPROFILE 'E-Notary-backups\test-storage'

foreach ($dir in 'app\private', 'app\public', 'framework\cache\data', 'framework\sessions', 'framework\views', 'framework\testing', 'logs') {
    New-Item -ItemType Directory -Force (Join-Path $storage $dir) | Out-Null
}

$env:LARAVEL_STORAGE_PATH = $storage
# "discard", not "null": Laravel reads "null" as no channel and falls back to laravel.log.
$env:LOG_CHANNEL = 'discard'

Set-Location (Split-Path $PSScriptRoot -Parent)
php artisan test --do-not-cache-result @args
exit $LASTEXITCODE
