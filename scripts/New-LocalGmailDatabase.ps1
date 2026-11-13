# Creates enotary_gmail_test, the disposable database that Start-Local-Gmail.ps1 runs on, so
# real-email tests never touch enotary_local or any other permanent database.
#
#   powershell -ExecutionPolicy Bypass -File scripts\New-LocalGmailDatabase.ps1          create it
#   powershell -ExecutionPolicy Bypass -File scripts\New-LocalGmailDatabase.ps1 -Check   report only
#
# What it does, and nothing more:
#   1. creates the empty database (it refuses if the database already exists, and never drops one)
#   2. gives the app's own MySQL user, enotary_app, row rights on that one database only, the same
#      rights it has on enotary_local, so the site runs there exactly as it does normally
#   3. runs the migrations on it as root, through the mysql_admin connection. Migrations add no
#      rows, so the database starts with no accounts at all: no Admin, no real users.
#
# Every setting below applies to this script's own processes only. .env is not read or changed
# for the database name, and no other database is touched.

param([switch]$Check)

$ErrorActionPreference = 'Stop'

$database = 'enotary_gmail_test'
$permanent = @('enotary', 'enotary_local')
$appUser = 'enotary_app'
$mysql = 'C:\xampp\mysql\bin\mysql.exe'
$project = Split-Path $PSScriptRoot -Parent

function Stop-WithError([string]$Message) {
    Write-Host "NOT DONE: $Message" -ForegroundColor Red
    exit 1
}

function Invoke-Root([string]$Sql) {
    $output = & $mysql '--user=root' '--host=127.0.0.1' '--batch' '--skip-column-names' '-e' $Sql 2>&1
    if ($LASTEXITCODE -ne 0) { Stop-WithError "MySQL refused: $output" }

    return @($output)
}

if ($permanent -contains $database) { Stop-WithError "$database is a permanent database." }
if (-not (Test-Path $mysql)) { Stop-WithError "MySQL client not found at $mysql." }

$exists = @(Invoke-Root "SHOW DATABASES LIKE '$database'").Count -gt 0

if ($Check) {
    if (-not $exists) { Write-Host "$database does not exist yet. Run this script without -Check to create it."; exit 1 }
    $users = Invoke-Root "SELECT COUNT(*) FROM ``$database``.users"
    $admins = Invoke-Root "SELECT COUNT(*) FROM ``$database``.users WHERE role = 'admin'"
    $migrations = Invoke-Root "SELECT COUNT(*) FROM ``$database``.migrations"
    Write-Host "Database:    $database"
    Write-Host "Migrations:  $migrations run"
    Write-Host "Accounts:    $users ($admins Admin)"
    exit 0
}

if ($exists) { Stop-WithError "$database already exists. Nothing was changed. Use -Check to see what it holds." }

# The app user's hosts, as they are set up for enotary_local. Only those hosts get rights here.
$hosts = @(Invoke-Root "SELECT host FROM mysql.user WHERE user = '$appUser'")
if ($hosts.Count -eq 0) { Stop-WithError "The MySQL user $appUser does not exist." }

Write-Host "Creating $database..."
Invoke-Root "CREATE DATABASE ``$database`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" | Out-Null
foreach ($h in $hosts) {
    Invoke-Root "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES, LOCK TABLES ON ``$database``.* TO '$appUser'@'$h'" | Out-Null
}

# Migrations as root, on this database only. Mail, queue, cache and SMS stay inert while they run.
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = $database
$env:MAIL_MAILER = 'log'
$env:QUEUE_CONNECTION = 'sync'
$env:CACHE_STORE = 'array'
$env:MSG91_ENABLED = 'false'
Remove-Item Env:MAIL_MODE -ErrorAction SilentlyContinue

Set-Location $project
& php artisan migrate --database=mysql_admin --force
if ($LASTEXITCODE -ne 0) { Stop-WithError "The migrations did not finish. $database was left in place for inspection; nothing else was touched." }

$users = Invoke-Root "SELECT COUNT(*) FROM ``$database``.users"
$migrations = Invoke-Root "SELECT COUNT(*) FROM ``$database``.migrations"
Write-Host ''
Write-Host "Ready: $database, $migrations migrations, $users accounts. $appUser has row rights on it only." -ForegroundColor Green
Write-Host 'Test accounts are created there separately, after the owner approves the mailboxes.'
