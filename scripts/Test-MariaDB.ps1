# Runs the automated tests on a throwaway MariaDB database, because SQLite hides faults
# that only MariaDB shows: missing columns, column types, unique indexes and row locks.
#
#   powershell -ExecutionPolicy Bypass -File scripts\Test-MariaDB.ps1 [phpunit arguments]
#
# The database is created here, used by these tests and dropped afterwards. Its name is
# fixed, and tests/TestCase.php refuses any other, so this cannot reach real data. Mail,
# queue, session, cache and SMS stay fakes: this proves the database, not any provider.

$ErrorActionPreference = 'Stop'

$database = 'enotary_phpunit'
$mysql = 'C:\xampp\mysql\bin\mysql.exe'
$storage = Join-Path $env:USERPROFILE 'E-Notary-backups\test-storage'

foreach ($dir in 'app\private', 'app\public', 'framework\cache\data', 'framework\sessions', 'framework\views', 'framework\testing', 'logs') {
    New-Item -ItemType Directory -Force (Join-Path $storage $dir) | Out-Null
}

Set-Location (Split-Path $PSScriptRoot -Parent)

& $mysql "--user=root" "--host=127.0.0.1" -e "DROP DATABASE IF EXISTS $database; CREATE DATABASE $database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

if ($LASTEXITCODE -ne 0) {
    throw 'Could not create the throwaway test database.'
}

$env:LARAVEL_STORAGE_PATH = $storage
$env:LOG_CHANNEL = 'discard'  # "null" reads as no channel, which falls back to laravel.log
$env:DB_CONNECTION = 'mysql'
$env:DB_HOST = '127.0.0.1'
$env:DB_DATABASE = $database
$env:DB_URL = ''

# Tests load .env.testing, never .env (phpunit.xml), so the account that may use the throwaway database
# is passed here: only DB_USERNAME and DB_PASSWORD are read from .env, and they are never printed.
# tests/TestCase.php still refuses any database but enotary_phpunit on this host.
foreach ($line in Get-Content (Join-Path (Get-Location) '.env')) {
    if ($line -match '^\s*(DB_USERNAME|DB_PASSWORD)\s*=\s*(.*)$') {
        Set-Item -Path ("env:" + $Matches[1]) -Value ($Matches[2].Trim().Trim('"').Trim("'"))
    }
}

try {
    php artisan test --do-not-cache-result @args
    $code = $LASTEXITCODE
} finally {
    & $mysql "--user=root" "--host=127.0.0.1" -e "DROP DATABASE IF EXISTS $database;"
}

exit $code
