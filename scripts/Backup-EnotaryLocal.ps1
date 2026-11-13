# Backup-EnotaryLocal.ps1 - makes a verified, trigger-complete backup package of enotary_local.
#
# READ-ONLY for the database: it runs mysqldump --single-transaction and SELECT / SHOW / CHECKSUM
# TABLE only. It never writes to enotary_local.
#
#   powershell -ExecutionPolicy Bypass -File scripts\Backup-EnotaryLocal.ps1 -Kind prelaunch
#   powershell -ExecutionPolicy Bypass -File scripts\Backup-EnotaryLocal.ps1 -EnvFile C:\xampp\htdocs\E-Notary\enotary-project\.env
#   ... -PrivilegedOptionFile C:\secure\enotary_backup.cnf    (dumps with --triggers --events)
#
# Package written to <BackupRoot>\enotary_local-<Kind>-<yyyyMMdd-HHmmss>\ :
#   enotary_local.sql      schema + data (one INSERT per row, hex blobs, utf8mb4, routines).
#                          As the app account this is made with --skip-triggers on purpose: the app
#                          account has no TRIGGER/EVENT privilege, so mysqldump cannot read them.
#   triggers.sql           every trigger rebuilt from the server's .TRG files, original DEFINER,
#                          per-trigger sql_mode and character-set context. Load after the data.
#   trigger-files\         raw copies of the .TRG / .TRN files.
#   mysqldump.log          mysqldump's warnings/errors (no credentials).
#   manifest.json          timestamps, tables + row counts + checksums, migration/FK/index/trigger
#                          counts, trigger list with body md5, sha256 of every other file.
#   <file>.sha256          one per file (manifest.json included); SHA256SUMS.txt lists them all.
# The folder is restricted with icacls to the current Windows user only (it holds password hashes
# and personal data). Copy it off the machine encrypted; see docs/BACKUP_AND_RESTORE_RUNBOOK.md.
#
# Exit code 0 = package written and verified. Anything else = FAILED; the partial folder is renamed
# to <name>.FAILED so it can never be mistaken for a good backup or picked up by retention.
#
# Retention (-Keep N, default 14, 0 = never delete): after a successful run, only folders directly
# in BackupRoot whose name matches  enotary_local-<same Kind>-<yyyyMMdd-HHmmss>  exactly AND whose
# manifest.json says createdBy = Backup-EnotaryLocal.ps1 are considered; the newest N are kept and
# older ones deleted. Nothing else (old .sql files, other folders, .FAILED folders) is ever touched.

[CmdletBinding()]
param(
    [ValidateSet('prelaunch', 'automatic')] [string]$Kind = 'automatic',
    # .env holding DB_USERNAME / DB_PASSWORD of the app account. Default: this project's .env.
    [string]$EnvFile = '',
    [string]$Database = 'enotary_local',
    [string]$BackupRoot = (Join-Path $env:USERPROFILE 'E-Notary-backups'),
    [string]$MysqlBin = 'C:\xampp\mysql\bin',
    [string]$DataDir = 'C:\xampp\mysql\data',
    # Triggers the .TRG files must contain. Update when a migration adds or removes a trigger.
    [int]$ExpectedTriggerCount = 10,
    [ValidateRange(0, 1000)] [int]$Keep = 14,
    # Optional option file ([client] user/password) of a backup account with SELECT, SHOW VIEW,
    # TRIGGER, EVENT, LOCK TABLES. When given, the dump includes triggers and events itself.
    [string]$PrivilegedOptionFile = ''
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2
. (Join-Path $PSScriptRoot 'lib\EnotaryDb.ps1')
Set-EnotaryUtf8Console
# $PSScriptRoot is empty inside param() defaults on Windows PowerShell 5.1, so resolve here.
if (-not $EnvFile) { $EnvFile = Join-Path (Split-Path $PSScriptRoot -Parent) '.env' }

$scriptName = 'Backup-EnotaryLocal.ps1'
# ':' in a .NET format string is the culture's time separator ('.' on this machine); use invariant.
$inv = [System.Globalization.CultureInfo]::InvariantCulture
$formatVersion = 2
# Tables the running app writes to constantly; a row-count drift there is reported, not fatal.
$volatile = @('cache', 'cache_locks', 'jobs', 'sessions')
$packageRx = '^' + [regex]::Escape($Database) + '-(prelaunch|automatic)-\d{8}-\d{6}$'

function Fail([string]$Message) { throw "BACKUP FAILED: $Message" }

$optionFile = $null
$tempDir = $null
$package = $null
$succeeded = $false
try {
    # ---- 1. Preconditions --------------------------------------------------------------------
    if ($Database -notmatch '^[A-Za-z0-9_]+$') { Fail 'Invalid database name.' }
    Set-EnotaryMysqlBin $MysqlBin
    $settings = Read-EnotaryDbEnv $EnvFile
    if ($settings['DB_DATABASE'] -ne $Database) { Fail "The env file points at '$($settings['DB_DATABASE'])', not $Database." }
    if (-not $settings['DB_USERNAME'] -or $settings['DB_USERNAME'] -eq 'root') { Fail 'The app account must not be root.' }
    if ($PrivilegedOptionFile -and -not (Test-Path -LiteralPath $PrivilegedOptionFile)) { Fail "Option file $PrivilegedOptionFile not found." }
    if ($PrivilegedOptionFile -and -not (Test-EnotaryPathPrivate $PrivilegedOptionFile)) { Fail "$PrivilegedOptionFile is readable by other accounts; restrict it first (icacls)." }

    # Temporary option file for the app account, in a private temp folder, deleted in finally.
    $tempDir = Join-Path ([System.IO.Path]::GetTempPath()) ('enotary-backup-' + [guid]::NewGuid().ToString('N'))
    $null = New-Item -ItemType Directory -Path $tempDir
    Protect-EnotaryPath $tempDir
    $optionFile = New-EnotaryOptionFile $settings $tempDir
    $dumpOptionFile = if ($PrivilegedOptionFile) { $PrivilegedOptionFile } else { $optionFile }

    $who = @(Get-EnotaryRows $optionFile 'SELECT CURRENT_USER()')[0]
    $serverVersion = @(Get-EnotaryRows $optionFile 'SELECT VERSION()')[0]
    $dumpVersion = (& (Get-EnotaryMysqlTool 'mysqldump.exe') --version) -join ' '

    # ---- 2. Package folder (private before anything is written) -----------------------------
    if (-not (Test-Path -LiteralPath $BackupRoot)) { $null = New-Item -ItemType Directory -Path $BackupRoot }
    $started = Get-Date
    $stamp = $started.ToString('yyyyMMdd-HHmmss')
    $name = "$Database-$Kind-$stamp"
    $package = Join-Path $BackupRoot $name
    if (Test-Path -LiteralPath $package) { Fail "$package already exists." }
    $null = New-Item -ItemType Directory -Path $package
    Protect-EnotaryPath $package
    Write-Host "Backing up $Database as $who into $package"

    # ---- 3. Triggers from the data directory (before the dump) ------------------------------
    $trgDir = Join-Path $DataDir $Database
    $triggerFilesBefore = @(Get-ChildItem -LiteralPath $trgDir -File | Where-Object { $_.Extension -in '.TRG', '.TRN' } | Sort-Object Name)
    $hashesBefore = @{}; foreach ($f in $triggerFilesBefore) { $hashesBefore[$f.Name] = Get-EnotarySha256 $f.FullName }
    $triggers = Get-EnotaryTriggerFiles $DataDir $Database
    if ($triggers.Count -ne $ExpectedTriggerCount) { Fail "The .TRG files hold $($triggers.Count) triggers; expected $ExpectedTriggerCount." }
    $rawDir = Join-Path $package 'trigger-files'
    $null = New-Item -ItemType Directory -Path $rawDir
    foreach ($f in $triggerFilesBefore) { Copy-Item -LiteralPath $f.FullName -Destination (Join-Path $rawDir $f.Name) }

    # ---- 4. The dump ----------------------------------------------------------------------------
    $dumpFile = Join-Path $package "$Database.sql"
    $dumpLog = Join-Path $package 'mysqldump.log'
    $dumpArgs = @("--defaults-extra-file=$dumpOptionFile", '--single-transaction', '--routines', '--hex-blob',
        '--default-character-set=utf8mb4', '--no-tablespaces', '--skip-extended-insert', '--quick',
        "--log-error=$dumpLog", "--result-file=$dumpFile")
    if ($PrivilegedOptionFile) { $dumpArgs += @('--triggers', '--events') }
    else { $dumpArgs += '--skip-triggers' }   # app account: no TRIGGER/EVENT privilege, see header
    $dumpArgs += $Database
    $previous = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try { $dumpOut = & (Get-EnotaryMysqlTool 'mysqldump.exe') @dumpArgs 2>&1; $dumpExit = $LASTEXITCODE }
    finally { $ErrorActionPreference = $previous }
    if ($dumpExit -ne 0) { Fail "mysqldump exited with code $dumpExit. $dumpOut $(if (Test-Path $dumpLog) { Get-Content $dumpLog -Raw })" }
    if (-not (Test-Path -LiteralPath $dumpLog)) { Write-EnotaryUtf8File $dumpLog '' }

    # Live checks right after the dump, while drift is smallest.
    $tables = @(Get-EnotaryRows $optionFile "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$Database' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")
    $snap = Get-EnotarySnapshot -OptionFile $optionFile -Database $Database -WithData

    # ---- 5. Verify the dump -------------------------------------------------------------------
    $dumpItem = Get-Item -LiteralPath $dumpFile
    if ($dumpItem.Length -le 0) { Fail 'The dump file is empty.' }
    # [string] strips the PSPath/PSDrive note properties Windows PowerShell 5.1 attaches (they would bloat the JSON).
    $last = [string](@(Get-Content -LiteralPath $dumpFile -Tail 5 | Where-Object { $_.Trim() })[-1])
    if ($last -notlike '-- Dump completed*') { Fail 'The dump does not end with "-- Dump completed"; it is incomplete.' }
    $created = @{}; $inserted = @{}; $dangerous = @(); $dumpTriggerCount = 0
    foreach ($line in [System.IO.File]::ReadLines($dumpFile)) {
        if ($line.StartsWith('INSERT INTO `')) {
            $t = $line.Substring(13, $line.IndexOf('`', 13) - 13); $inserted[$t] = 1 + [int]$inserted[$t]
        } elseif ($line -match '^CREATE TABLE `([^`]+)`') { $created[$Matches[1]] = $true }
        elseif ($line -match '^\s*(USE\s|CREATE DATABASE|DROP DATABASE)') { $dangerous += $line.Substring(0, [Math]::Min(60, $line.Length)) }
        elseif ($line -match 'TRIGGER\s+`?[A-Za-z0-9_]+`?\s+(BEFORE|AFTER)\s') { $dumpTriggerCount++ }
    }
    if ($dangerous.Count -gt 0) { Fail "The dump contains database-switching statements: $($dangerous -join '; ')" }
    $problems = @(); $drift = @(); $tableRows = @(); $totalRows = 0
    $liveCounts = @{}; foreach ($r in $snap.RowCounts) { $p = $r.Split('|'); $liveCounts[$p[0]] = [long]$p[1] }
    $checks = @{}; foreach ($r in $snap.Checksums) { $p = $r.Split('|'); $checks[$p[0]] = $p[1] }
    foreach ($t in $tables) {
        if (-not $created.ContainsKey($t)) { $problems += "table $t missing from the dump"; continue }
        $saved = [long]$inserted[$t]; $totalRows += $saved
        if ($saved -ne $liveCounts[$t]) {
            if ($volatile -contains $t) { $drift += "$t (dump $saved, live $($liveCounts[$t]))" }
            else { $problems += "table $t has $saved rows in the dump but $($liveCounts[$t]) live" }
        }
        $tableRows += [ordered]@{ name = $t; rows_in_dump = $saved; rows_live_after_dump = $liveCounts[$t]; checksum_live_after_dump = $checks[$t] }
    }
    foreach ($t in $created.Keys) { if ($tables -notcontains $t) { $problems += "dump has unknown table $t" } }
    if ($PrivilegedOptionFile -and $dumpTriggerCount -ne $triggers.Count) { $problems += "dump holds $dumpTriggerCount triggers, expected $($triggers.Count)" }
    if ($problems.Count -gt 0) { Fail ($problems -join '; ') }

    # Trigger files must not have changed while we worked (a migration running mid-backup).
    $triggerFilesAfter = @(Get-ChildItem -LiteralPath $trgDir -File | Where-Object { $_.Extension -in '.TRG', '.TRN' })
    if ($triggerFilesAfter.Count -ne $triggerFilesBefore.Count) { Fail 'Trigger files appeared or vanished during the backup.' }
    foreach ($f in $triggerFilesAfter) { if ($hashesBefore[$f.Name] -ne (Get-EnotarySha256 $f.FullName)) { Fail "$($f.Name) changed during the backup." } }

    # ---- 6. triggers.sql ------------------------------------------------------------------------
    $modeNames = @{}
    foreach ($n in @($triggers | ForEach-Object { $_.SqlModeNumber } | Sort-Object -Unique)) { $modeNames[[string]$n] = Resolve-EnotarySqlMode $optionFile $n }
    $triggersFile = Join-Path $package 'triggers.sql'
    Write-EnotaryUtf8File $triggersFile (New-EnotaryTriggersSql $triggers $Database $modeNames $started.ToString('o'))
    $writtenCount = @(Select-String -LiteralPath $triggersFile -Pattern '^CREATE DEFINER=\S+ TRIGGER ').Count
    if ($writtenCount -ne $ExpectedTriggerCount) { Fail "triggers.sql holds $writtenCount triggers; expected $ExpectedTriggerCount." }
    if (-not ((Get-Content -LiteralPath $triggersFile -Tail 1) -like "-- Triggers completed: $ExpectedTriggerCount")) { Fail 'triggers.sql is incomplete.' }

    # ---- 7. Manifest and checksums --------------------------------------------------------------
    $migrationCount = $snap.Migrations.Count
    $maxBatch = if ($migrationCount) { ($snap.Migrations | ForEach-Object { [int]$_.Split('|')[2] } | Measure-Object -Maximum).Maximum } else { 0 }
    $files = @()
    foreach ($f in @(Get-ChildItem -LiteralPath $package -File -Recurse | Sort-Object FullName)) {
        $rel = $f.FullName.Substring($package.Length + 1).Replace('\', '/')
        $files += [ordered]@{ path = $rel; bytes = $f.Length; sha256 = (Get-EnotarySha256 $f.FullName) }
    }
    $manifest = [ordered]@{
        createdBy           = $scriptName
        formatVersion       = $formatVersion
        kind                = $Kind
        package             = $name
        startedLocal        = $started.ToString('yyyy-MM-ddTHH:mm:sszzz', $inv)
        startedUtc          = $started.ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ', $inv)
        finishedLocal       = (Get-Date).ToString('yyyy-MM-ddTHH:mm:sszzz', $inv)
        computer            = $env:COMPUTERNAME
        database            = $Database
        databaseCollation   = @(Get-EnotaryRows $optionFile "SELECT CONCAT_WS('|', DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$Database'")[0]
        dumpAccount         = $(if ($PrivilegedOptionFile) { 'privileged option file' } else { $who })
        serverVersion       = $serverVersion
        mysqldumpVersion    = $dumpVersion
        dumpOptions         = @($dumpArgs | Where-Object { $_ -notlike '--defaults-extra-file=*' -and $_ -notlike '--result-file=*' -and $_ -notlike '--log-error=*' })
        dumpIncludesTriggers = [bool]$PrivilegedOptionFile
        dumpIncludesEvents  = [bool]$PrivilegedOptionFile
        eventsNote          = $(if ($PrivilegedOptionFile) { '' } else { 'App account lacks EVENT privilege; --events omitted. Server event_scheduler is OFF and mysql.event has never been written (2026-11-10 check).' })
        dumpCompletedLine   = $last
        tableCount          = $tables.Count
        totalRowsInDump     = $totalRows
        tables              = $tableRows
        volatileTables      = $volatile
        volatileDrift       = $drift
        migrationCount      = $migrationCount
        migrationMaxBatch   = $maxBatch
        foreignKeyCount     = $snap.ForeignKeyCount
        indexCount          = $snap.IndexCount
        viewCount           = $snap.Views.Count
        triggerCount        = $triggers.Count
        triggers            = @($triggers | ForEach-Object { [ordered]@{ name = $_.Name; table = $_.Table; timing = $_.Timing; event = $_.Event;
                                actionOrder = $_.ActionOrder; definer = $_.Definer; sqlMode = $modeNames[[string]$_.SqlModeNumber];
                                bodyMd5 = $_.BodyMd5; statementWithoutDefinerMd5 = $_.StatementNoDefinerMd5 } })
        files               = $files
    }
    $manifestFile = Join-Path $package 'manifest.json'
    Write-EnotaryUtf8File $manifestFile ($manifest | ConvertTo-Json -Depth 6)
    # Sanity: a manifest is a few KB. Anything large means an object leaked into the JSON.
    if ((Get-Item -LiteralPath $manifestFile).Length -gt 1MB) { Fail 'manifest.json is unexpectedly large; refusing to finish.' }
    $null = Get-Content -LiteralPath $manifestFile -Raw | ConvertFrom-Json   # must parse back

    $sums = @()
    foreach ($f in @(Get-ChildItem -LiteralPath $package -File -Recurse | Where-Object { $_.Extension -ne '.sha256' } | Sort-Object FullName)) {
        $h = Get-EnotarySha256 $f.FullName
        Write-EnotaryUtf8File "$($f.FullName).sha256" "$h  $($f.Name)`n"
        $sums += "$h  $($f.FullName.Substring($package.Length + 1).Replace('\', '/'))"
    }
    Write-EnotaryUtf8File (Join-Path $package 'SHA256SUMS.txt') (($sums -join "`n") + "`n")

    # ---- 8. Permissions check -------------------------------------------------------------------
    foreach ($item in @(Get-Item -LiteralPath $package) + @(Get-ChildItem -LiteralPath $package -Recurse -Force)) {
        $others = @((Get-Acl -LiteralPath $item.FullName).Access | Where-Object { $_.IdentityReference.Value -ne "$($env:USERDOMAIN)\$($env:USERNAME)" })
        if ($others.Count -gt 0) { Fail "$($item.FullName) is accessible to $($others[0].IdentityReference.Value)." }
    }
    if (-not (Test-EnotaryPathPrivate $package)) { Fail 'The package folder is not private.' }
    $succeeded = $true

    # ---- 9. Retention (only our own packages of the same kind) ----------------------------------
    $deleted = @()
    if ($Keep -gt 0) {
        $ours = @(Get-ChildItem -LiteralPath $BackupRoot -Directory | Where-Object {
            $_.Name -match $packageRx -and $_.Name -like "$Database-$Kind-*" -and
            -not ($_.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -and
            (Test-Path -LiteralPath (Join-Path $_.FullName 'manifest.json'))
        } | Where-Object {
            try { (Get-Content -LiteralPath (Join-Path $_.FullName 'manifest.json') -Raw | ConvertFrom-Json).createdBy -eq $scriptName } catch { $false }
        } | Sort-Object Name -Descending)
        foreach ($old in @($ours | Select-Object -Skip $Keep)) {
            if ($old.FullName -eq $package) { continue }
            if ((Split-Path $old.FullName -Parent) -ne (Get-Item -LiteralPath $BackupRoot).FullName.TrimEnd('\')) { continue }
            Remove-Item -LiteralPath $old.FullName -Recurse -Force
            $deleted += $old.Name
        }
    }

    # ---- 10. Summary ----------------------------------------------------------------------------
    $dumpHash = Get-EnotarySha256 $dumpFile
    Write-Host ''
    Write-Host 'Backup verified.' -ForegroundColor Green
    Write-Host "  Package:      $package"
    Write-Host "  Dump:         $([math]::Round($dumpItem.Length / 1KB)) KB  sha256 $dumpHash"
    Write-Host "  Completed:    $last"
    Write-Host "  Tables:       $($tables.Count), rows in dump $totalRows, every non-volatile row count matches live"
    Write-Host "  Migrations:   $migrationCount (max batch $maxBatch)   FKs: $($snap.ForeignKeyCount)   Indexes: $($snap.IndexCount)"
    Write-Host "  Triggers:     $($triggers.Count) of $ExpectedTriggerCount in triggers.sql$(if ($PrivilegedOptionFile) { ' and in the dump' })"
    if ($drift.Count) { Write-Host "  Volatile drift (expected): $($drift -join ', ')" -ForegroundColor Yellow }
    Write-Host "  Permissions:  $($env:USERDOMAIN)\$($env:USERNAME) only"
    Write-Host "  Retention:    keep $Keep '$Kind' packages; deleted: $(if ($deleted.Count) { $deleted -join ', ' } else { 'none' })"
    exit 0
} catch {
    $msg = $_.Exception.Message
    if ($msg -notlike 'BACKUP FAILED*') { $msg = "BACKUP FAILED: $msg (at line $($_.InvocationInfo.ScriptLineNumber))" }
    Write-Host $msg -ForegroundColor Red
    if ($package -and -not $succeeded -and (Test-Path -LiteralPath $package)) {
        try { Rename-Item -LiteralPath $package -NewName ((Split-Path $package -Leaf) + '.FAILED'); Write-Host "Partial output kept as $package.FAILED" } catch { }
    }
    exit 1
} finally {
    Remove-EnotaryOptionFile $optionFile
    if ($tempDir -and (Test-Path -LiteralPath $tempDir)) { Remove-Item -LiteralPath $tempDir -Recurse -Force }
}
