# Test-CleanInstallSchema.ps1 - release check: does a clean `php artisan migrate` reproduce the live schema?
#
#   powershell -ExecutionPolicy Bypass -File scripts\Test-CleanInstallSchema.ps1 -EnvFile C:\xampp\htdocs\E-Notary\enotary-project\.env
#
#   1. Creates the THROWAWAY database enotary_phpunit as the app account (refuses if it exists).
#   2. Points THIS project (normally the staging copy) at it through process environment variables
#      only (DB_CONNECTION/DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD; Laravel's .env loader
#      never overrides variables already set). Nothing is written to any .env file.
#   3. Proves the target BEFORE migrating: config:show must report connection mysql and database
#      enotary_phpunit, and a booted app must report SELECT DATABASE() = enotary_phpunit.
#      Any other answer aborts. Only then: php artisan migrate --force --database=mysql.
#   4. Compares the result with the live database (READ-ONLY): tables, columns (type, null, default,
#      extra, charset, collation), indexes, foreign keys (incl. ON UPDATE/DELETE), table options,
#      triggers (count + body md5; definer ignored), migration names.
#   5. Drops enotary_phpunit and prints every difference. Exit 0 = identical, 2 = differences, 1 = error.
#
# Credentials go only into this process's environment and a private temporary option file; they are
# never printed. The script runs in its own powershell.exe process, so the variables die with it.

[CmdletBinding()]
param(
    [string]$EnvFile = 'C:\xampp\htdocs\E-Notary\enotary-project\.env',
    [string]$SourceDatabase = 'enotary_local',
    [string]$ProjectDir = '',          # default: the project this script lives in
    [string]$MysqlBin = 'C:\xampp\mysql\bin',
    [string]$DataDir = 'C:\xampp\mysql\data',
    [string]$Php = 'php'
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2
. (Join-Path $PSScriptRoot 'lib\EnotaryDb.ps1')
Set-EnotaryUtf8Console
# $PSScriptRoot is empty inside param() defaults on Windows PowerShell 5.1, so resolve here.
if (-not $ProjectDir) { $ProjectDir = Split-Path $PSScriptRoot -Parent }
Set-Variable -Name Target -Value 'enotary_phpunit' -Option ReadOnly -Scope Script
$inv = [System.Globalization.CultureInfo]::InvariantCulture
function Note([string]$Text) { Write-Host "[$((Get-Date).ToString('yyyy-MM-dd HH:mm:ss', $inv))] $Text" }

# Runs php artisan in the project directory; returns trimmed stdout lines, throws on failure.
function Invoke-Artisan([string[]]$ArtisanArgs, [switch]$Show) {
    Push-Location -LiteralPath $ProjectDir
    $previous = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try { $out = & $Php artisan @ArtisanArgs 2>&1; $code = $LASTEXITCODE }
    finally { $ErrorActionPreference = $previous; Pop-Location }
    $lines = @($out | ForEach-Object { ([string]$_).TrimEnd() } | Where-Object { $_ -ne '' })
    if ($Show) { $lines | ForEach-Object { Write-Host "    $_" } }
    if ($code -ne 0) { throw "php artisan $($ArtisanArgs -join ' ') failed (exit $code): $($lines -join ' / ')" }
    return $lines
}

$optionFile = $null; $tempDir = $null; $created = $false; $exitCode = 1
$envNames = @('DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL')
try {
    Set-EnotaryMysqlBin $MysqlBin
    $settings = Read-EnotaryDbEnv $EnvFile
    if (-not $settings['DB_USERNAME'] -or $settings['DB_USERNAME'] -eq 'root') { throw 'Use the app account, never root.' }
    if (-not (Test-Path -LiteralPath (Join-Path $ProjectDir 'artisan'))) { throw "$ProjectDir is not a Laravel project." }
    if (Test-Path -LiteralPath (Join-Path $ProjectDir 'bootstrap\cache\config.php')) { throw 'A cached config exists (bootstrap\cache\config.php); environment overrides would be ignored. Refusing.' }

    $tempDir = Join-Path ([System.IO.Path]::GetTempPath()) ('enotary-clean-' + [guid]::NewGuid().ToString('N'))
    $null = New-Item -ItemType Directory -Path $tempDir; Protect-EnotaryPath $tempDir
    $optionFile = New-EnotaryOptionFile $settings $tempDir

    # ---- 1. Fresh empty throwaway database -----------------------------------------------------
    if ([int](@(Get-EnotaryRows $optionFile "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$Target'")[0])) { throw "Refused: $Target already exists." }
    Note "CREATE DATABASE ``$Target`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    $null = Invoke-EnotarySql -OptionFile $optionFile -Sql "CREATE DATABASE ``$Target`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    $created = $true

    # ---- 2. Point the project at it (process environment only) ---------------------------------
    $env:DB_CONNECTION = 'mysql'; $env:DB_HOST = '127.0.0.1'; $env:DB_PORT = $settings['DB_PORT']
    $env:DB_DATABASE = $Target; $env:DB_USERNAME = $settings['DB_USERNAME']; $env:DB_PASSWORD = $settings['DB_PASSWORD']
    $env:DB_URL = ''

    # ---- 3. Prove the target before migrating ---------------------------------------------------
    Note 'Verifying the migration target'
    $default = (Invoke-Artisan @('config:show', 'database.default')) -join ' '
    $dbName = (Invoke-Artisan @('config:show', 'database.connections.mysql.database')) -join ' '
    $dbHost = (Invoke-Artisan @('config:show', 'database.connections.mysql.host')) -join ' '
    Write-Host "    database.default => $default"
    Write-Host "    database.connections.mysql.database => $dbName"
    Write-Host "    database.connections.mysql.host => $dbHost"
    if ($default -notmatch '\bmysql\s*$' -or $dbName -notmatch "\b$Target\s*$") { throw "Target check failed; refusing to migrate." }
    $boot = (Invoke-Artisan @('tinker', '--execute=echo ''BOOTCHECK=''.Illuminate\Support\Facades\DB::connection()->selectOne(''SELECT DATABASE() AS d'')->d;')) -join ' '
    Write-Host "    booted app: $boot"
    if ($boot -notmatch "BOOTCHECK=$Target\b") { throw 'Boot check failed; refusing to migrate.' }

    # ---- 4. Migrate -------------------------------------------------------------------------------
    Note "php artisan migrate --force --database=mysql  (target $Target)"
    $null = Invoke-Artisan @('migrate', '--force', '--database=mysql', '--no-interaction') -Show

    # ---- 5. Compare (live is read-only) -----------------------------------------------------------
    Note "Comparing clean install ($Target) with live $SourceDatabase"
    $live = Get-EnotarySnapshot -OptionFile $optionFile -Database $SourceDatabase
    $fresh = Get-EnotarySnapshot -OptionFile $optionFile -Database $Target -WithServerTriggers
    # Assign first: the helper returns its array as one pipeline object (return , $array).
    $liveTriggerObjects = Get-EnotaryTriggerFiles $DataDir $SourceDatabase
    $liveTrig = @($liveTriggerObjects | ForEach-Object { "$($_.Name)|$($_.Table)|$($_.Timing)|$($_.Event)|$($_.ActionOrder)|$($_.BodyMd5)" } | Sort-Object)
    $liveMig = @($live.Migrations | ForEach-Object { $_.Split('|')[1] })
    $freshMig = @($fresh.Migrations | ForEach-Object { $_.Split('|')[1] })
    $differences = 0
    $checks = [ordered]@{
        'Tables' = @($live.Tables, $fresh.Tables)
        'Columns (table|pos|name|type|null|default|extra|charset|collation|key)' = @($live.Columns, $fresh.Columns)
        'Indexes (table|index|non_unique|seq|column|sub_part|type)' = @($live.Indexes, $fresh.Indexes)
        'Foreign keys (table|name|cols|ref table|ref cols|on update|on delete)' = @($live.ForeignKeys, $fresh.ForeignKeys)
        'Check constraints' = @($live.CheckConstraints, $fresh.CheckConstraints)
        'Table options (engine|row format|collation)' = @($live.TableOptions, $fresh.TableOptions)
        'Triggers (name|table|timing|event|order|body md5), definer ignored' = @($liveTrig, $fresh.Triggers)
        'Migration names' = @($liveMig, $freshMig)
    }
    foreach ($k in $checks.Keys) {
        $d = Compare-EnotaryList $checks[$k][0] $checks[$k][1]
        if ($d.Pass) { Write-Host ("  SAME  {0}: {1} entries" -f $k, @($checks[$k][0]).Count) -ForegroundColor Green }
        else {
            $differences++
            Write-Host ("  DIFF  {0}: live {1}, clean {2}" -f $k, @($checks[$k][0]).Count, @($checks[$k][1]).Count) -ForegroundColor Yellow
            $d.OnlyLeft | ForEach-Object { Write-Host "        only live : $_" }
            $d.OnlyRight | ForEach-Object { Write-Host "        only clean: $_" }
        }
    }
    Write-Host "  Counts: live tables $($live.Tables.Count) / FKs $($live.ForeignKeyCount) / indexes $($live.IndexCount) / triggers $($liveTrig.Count) / migrations $($liveMig.Count)"
    Write-Host "          clean tables $($fresh.Tables.Count) / FKs $($fresh.ForeignKeyCount) / indexes $($fresh.IndexCount) / triggers $(@($fresh.Triggers).Count) / migrations $($freshMig.Count)"
    $exitCode = if ($differences) { 2 } else { 0 }
} catch {
    Note "ERROR: $($_.Exception.Message) (line $($_.InvocationInfo.ScriptLineNumber))"
    $exitCode = 1
} finally {
    foreach ($n in $envNames) { Remove-Item -LiteralPath "Env:\$n" -ErrorAction SilentlyContinue }
    if ($created -and $optionFile) {
        try {
            $null = Invoke-EnotarySql -OptionFile $optionFile -Sql "DROP DATABASE ``$Target``"
            $still = [int](@(Get-EnotaryRows $optionFile "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$Target'")[0])
            Note "DROP DATABASE ``$Target`` -> $(if ($still) { 'STILL PRESENT' } else { 'dropped' })"
            if ($still) { $exitCode = 1 }
        } catch { Note "Could not drop ${Target}: $($_.Exception.Message)"; $exitCode = 1 }
    }
    Remove-EnotaryOptionFile $optionFile
    if ($tempDir -and (Test-Path -LiteralPath $tempDir)) { Remove-Item -LiteralPath $tempDir -Recurse -Force }
}
exit $exitCode
