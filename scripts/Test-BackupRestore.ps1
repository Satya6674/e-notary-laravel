# Test-BackupRestore.ps1 - proves a Backup-EnotaryLocal.ps1 package restores correctly, in isolation.
#
#   powershell -ExecutionPolicy Bypass -File scripts\Test-BackupRestore.ps1 -EnvFile C:\xampp\htdocs\E-Notary\enotary-project\.env
#   ... -Package C:\Users\<you>\E-Notary-backups\enotary_local-prelaunch-20261110-183915
#
# What it does, in order (any failure = non-zero exit):
#   1. Verifies the package: every file's sha256 against manifest.json and the .sha256 files.
#   2. Creates the THROWAWAY database enotary_phpunit as the app account. It refuses to run if that
#      database already exists (someone else may be using it) and it can restore into no other
#      database: the target name is fixed. The dump is scanned first and refused if it contains a
#      USE / CREATE DATABASE / DROP DATABASE line, so it cannot write anywhere else.
#   3. Restores enotary_local.sql, then triggers.sql with the DEFINER clause REMOVED, so the restored
#      triggers belong to the app account (it may not create triggers for root@localhost). A real
#      restore keeps the definer and needs a privileged account; see docs/BACKUP_AND_RESTORE_RUNBOOK.md.
#   4. Compares the restored copy with the live source database (READ-ONLY: SELECT, information_schema,
#      CHECKSUM TABLE): tables, row counts (also against the counts recorded in the manifest),
#      CHECKSUM TABLE, foreign keys, indexes, columns, migrations rows, triggers (count, table,
#      timing, event, order, body md5 against the live .TRG files).
#   5. Proves the triggers fire, in the RESTORED database only: UPDATE and DELETE on audit_logs and
#      verification_events, and DELETE of a former-admin user, must each be refused with SQLSTATE 45000.
#   6. Drops enotary_phpunit (unless -KeepRestoredDatabase) and writes a report (counts, checksums and
#      hashes only, no personal data) to <BackupRoot>\restore-tests\.
#
# The live database is never written: every statement against it is a SELECT / SHOW / CHECKSUM TABLE.

[CmdletBinding()]
param(
    [string]$Package = '',
    [string]$EnvFile = '',             # default: this project's .env
    [string]$SourceDatabase = 'enotary_local',
    [string]$BackupRoot = (Join-Path $env:USERPROFILE 'E-Notary-backups'),
    [string]$MysqlBin = 'C:\xampp\mysql\bin',
    [string]$DataDir = 'C:\xampp\mysql\data',
    [switch]$KeepRestoredDatabase
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2
. (Join-Path $PSScriptRoot 'lib\EnotaryDb.ps1')
Set-EnotaryUtf8Console
# $PSScriptRoot is empty inside param() defaults on Windows PowerShell 5.1, so resolve here.
if (-not $EnvFile) { $EnvFile = Join-Path (Split-Path $PSScriptRoot -Parent) '.env' }

# Fixed on purpose: the only database the app account may create. ReadOnly, because PowerShell variable
# names are case-insensitive and an accidental $target = ... elsewhere would otherwise retarget the restore.
Set-Variable -Name Target -Value 'enotary_phpunit' -Option ReadOnly -Scope Script
$volatile = @('cache', 'cache_locks', 'jobs', 'sessions')
$inv = [System.Globalization.CultureInfo]::InvariantCulture
$results = New-Object System.Collections.Generic.List[object]
$log = New-Object System.Collections.Generic.List[string]

function Now { return (Get-Date).ToString('yyyy-MM-dd HH:mm:ss', $inv) }
function Note([string]$Text) { $line = "[$(Now)] $Text"; $log.Add($line); Write-Host $line }
function Add-Result([string]$Check, [string]$Status, [string]$Detail) {
    $results.Add([pscustomobject]@{ Check = $Check; Status = $Status; Detail = $Detail })
    $color = switch ($Status) { 'PASS' { 'Green' } 'WARN' { 'Yellow' } default { 'Red' } }
    Write-Host ("  {0,-4} {1}: {2}" -f $Status, $Check, $Detail) -ForegroundColor $color
}
function Show-Diff($Diff, [int]$Max = 6) {
    $parts = @()
    if ($Diff.OnlyLeft.Count) { $parts += "only live: " + (($Diff.OnlyLeft | Select-Object -First $Max) -join '; ') }
    if ($Diff.OnlyRight.Count) { $parts += "only restored: " + (($Diff.OnlyRight | Select-Object -First $Max) -join '; ') }
    return ($parts -join ' || ')
}

$optionFile = $null; $tempDir = $null; $created = $false; $exitCode = 1
try {
    Set-EnotaryMysqlBin $MysqlBin
    $settings = Read-EnotaryDbEnv $EnvFile
    if (-not $settings['DB_USERNAME'] -or $settings['DB_USERNAME'] -eq 'root') { throw 'Run this as the app account, never root.' }

    # ---- 1. Package integrity -------------------------------------------------------------------
    if (-not $Package) {
        $Package = @(Get-ChildItem -LiteralPath $BackupRoot -Directory | Where-Object { $_.Name -match ('^' + [regex]::Escape($SourceDatabase) + '-(prelaunch|automatic)-\d{8}-\d{6}$') } |
            Sort-Object { $_.Name.Substring($_.Name.Length - 15) } -Descending | Select-Object -First 1 | ForEach-Object { $_.FullName })[0]
        if (-not $Package) { throw "No backup package found in $BackupRoot." }
    }
    Note "Package: $Package"
    $manifest = Get-Content -LiteralPath (Join-Path $Package 'manifest.json') -Raw | ConvertFrom-Json
    if ($manifest.createdBy -ne 'Backup-EnotaryLocal.ps1' -or $manifest.database -ne $SourceDatabase) { throw 'manifest.json is not a Backup-EnotaryLocal.ps1 package of this database.' }
    $bad = @()
    foreach ($f in $manifest.files) {
        $path = Join-Path $Package ($f.path.Replace('/', '\'))
        if (-not (Test-Path -LiteralPath $path)) { $bad += "$($f.path) missing"; continue }
        if ((Get-EnotarySha256 $path) -ne $f.sha256) { $bad += "$($f.path) hash differs" }
    }
    foreach ($s in @(Get-ChildItem -LiteralPath $Package -Recurse -File -Filter '*.sha256')) {
        $expected = ((Get-Content -LiteralPath $s.FullName -Raw).Trim() -split '\s+')[0]
        $hashedFile = $s.FullName.Substring(0, $s.FullName.Length - 7)
        if ((Get-EnotarySha256 $hashedFile) -ne $expected) { $bad += "$($s.Name) does not match" }
    }
    if ($bad.Count) { Add-Result 'Package checksums' 'FAIL' ($bad -join '; '); throw 'Package integrity failed.' }
    Add-Result 'Package checksums' 'PASS' "$(@($manifest.files).Count) files match manifest.json and their .sha256 files"

    $dumpFile = Join-Path $Package "$SourceDatabase.sql"
    $triggersFile = Join-Path $Package 'triggers.sql'
    $last = [string](@(Get-Content -LiteralPath $dumpFile -Tail 5 | Where-Object { $_.Trim() })[-1])
    if ($last -notlike '-- Dump completed*') { throw 'The dump is incomplete (no "-- Dump completed").' }
    $danger = @(Select-String -LiteralPath $dumpFile, $triggersFile -Pattern '^\s*(USE\s|CREATE DATABASE|DROP DATABASE)')
    if ($danger.Count) { throw "Refused: the package switches or creates databases ($($danger.Count) lines)." }

    # ---- 2. Throwaway database --------------------------------------------------------------------
    $tempDir = Join-Path ([System.IO.Path]::GetTempPath()) ('enotary-restore-' + [guid]::NewGuid().ToString('N'))
    $null = New-Item -ItemType Directory -Path $tempDir
    Protect-EnotaryPath $tempDir
    $optionFile = New-EnotaryOptionFile $settings $tempDir
    Note "Connected as $(@(Get-EnotaryRows $optionFile 'SELECT CURRENT_USER()')[0])"
    $exists = [int](@(Get-EnotaryRows $optionFile "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$Target'")[0])
    if ($exists) { throw "Refused: $Target already exists. Someone may be using it; drop it yourself when it is free." }
    $cs = ([string]$manifest.databaseCollation).Split('|')
    Note "CREATE DATABASE ``$Target`` CHARACTER SET $($cs[0]) COLLATE $($cs[1])"
    $null = Invoke-EnotarySql -OptionFile $optionFile -Sql "CREATE DATABASE ``$Target`` CHARACTER SET $($cs[0]) COLLATE $($cs[1])"
    $created = $true

    # ---- 3. Restore --------------------------------------------------------------------------------
    Note "Restoring $(Split-Path $dumpFile -Leaf) into $Target (mysql --database=$Target < dump)"
    $t0 = Get-Date
    if ($manifest.dumpIncludesTriggers) {
        # Privileged package: the dump carries /*!50017 DEFINER=...*/ clauses; strip them for this account.
        $stripped = Join-Path $tempDir 'dump-nodefiner.sql'
        $reader = [System.IO.File]::OpenText($dumpFile); $writer = New-Object System.IO.StreamWriter($stripped, $false, (New-Object System.Text.UTF8Encoding($false)))
        try { while ($null -ne ($line = $reader.ReadLine())) { $writer.WriteLine(($line -replace '/\*!50017 DEFINER=`[^`]*`@`[^`]*`\*/\s*', '')) } }
        finally { $reader.Close(); $writer.Close() }
        Import-EnotarySqlFile $optionFile $Target $stripped (Join-Path $tempDir 'import-dump.err')
    } else {
        Import-EnotarySqlFile $optionFile $Target $dumpFile (Join-Path $tempDir 'import-dump.err')
        $noDefiner = Join-Path $tempDir 'triggers-nodefiner.sql'
        $text = [System.IO.File]::ReadAllText($triggersFile)
        $text2 = [regex]::Replace($text, '(?m)^CREATE DEFINER=\S+ TRIGGER ', 'CREATE TRIGGER ')
        $n = [regex]::Matches($text2, '(?m)^CREATE TRIGGER ').Count
        if ($n -ne [int]$manifest.triggerCount) { throw "triggers.sql has $n triggers after stripping DEFINER; manifest says $($manifest.triggerCount)." }
        Write-EnotaryUtf8File $noDefiner $text2
        Note "Restoring triggers.sql with DEFINER removed ($n triggers; they will be owned by the app account)"
        Import-EnotarySqlFile $optionFile $Target $noDefiner (Join-Path $tempDir 'import-triggers.err')
    }
    Note ("Restore finished in {0:N1} s" -f ((Get-Date) - $t0).TotalSeconds)

    # ---- 4. Compare with the live source (read-only) ------------------------------------------------
    Note "Comparing $Target with live $SourceDatabase (read-only)"
    $live = Get-EnotarySnapshot -OptionFile $optionFile -Database $SourceDatabase -WithData
    $rest = Get-EnotarySnapshot -OptionFile $optionFile -Database $Target -WithData -WithServerTriggers

    $d = Compare-EnotaryList $live.Tables $rest.Tables
    Add-Result 'Table list' $(if ($d.Pass) { 'PASS' } else { 'FAIL' }) $(if ($d.Pass) { "$($rest.Tables.Count) tables" } else { Show-Diff $d })

    # Row counts: restored vs manifest (exact restore fidelity) and restored vs live (drift since backup).
    $restCounts = @{}; foreach ($r in $rest.RowCounts) { $p = $r.Split('|'); $restCounts[$p[0]] = [long]$p[1] }
    $liveCounts = @{}; foreach ($r in $live.RowCounts) { $p = $r.Split('|'); $liveCounts[$p[0]] = [long]$p[1] }
    $vsManifest = @($manifest.tables | Where-Object { $restCounts[$_.name] -ne [long]$_.rows_in_dump } | ForEach-Object { "$($_.name): dump $($_.rows_in_dump), restored $($restCounts[$_.name])" })
    Add-Result 'Row counts vs manifest' $(if ($vsManifest.Count) { 'FAIL' } else { 'PASS' }) $(if ($vsManifest.Count) { $vsManifest -join '; ' } else { "all $(@($manifest.tables).Count) tables, $(($manifest.tables | Measure-Object rows_in_dump -Sum).Sum) rows" })
    $hard = @(); $soft = @()
    foreach ($t in $live.Tables) { if ($liveCounts[$t] -ne $restCounts[$t]) { $m = "${t}: live $($liveCounts[$t]), restored $($restCounts[$t])"; if ($volatile -contains $t) { $soft += $m } else { $hard += $m } } }
    Add-Result 'Row counts vs live' $(if ($hard.Count) { 'FAIL' } elseif ($soft.Count) { 'WARN' } else { 'PASS' }) $(if ($hard.Count -or $soft.Count) { (@($hard) + @($soft)) -join '; ' } else { 'every table equal' })

    $restSum = @{}; foreach ($r in $rest.Checksums) { $p = $r.Split('|'); $restSum[$p[0]] = $p[1] }
    $liveSum = @{}; foreach ($r in $live.Checksums) { $p = $r.Split('|'); $liveSum[$p[0]] = $p[1] }
    $hard = @(); $soft = @()
    foreach ($t in $live.Tables) { if ($liveSum[$t] -ne $restSum[$t]) { if ($volatile -contains $t) { $soft += $t } else { $hard += $t } } }
    Add-Result 'CHECKSUM TABLE vs live' $(if ($hard.Count) { 'FAIL' } elseif ($soft.Count) { 'WARN' } else { 'PASS' }) $(if ($hard.Count -or $soft.Count) { "differ: $((@($hard) + @($soft)) -join ', ')$(if ($soft.Count -and -not $hard.Count) { ' (volatile only)' })" } else { "all $($live.Tables.Count) tables identical" })
    $vsManifestSum = @($manifest.tables | Where-Object { $volatile -notcontains $_.name -and $restSum[$_.name] -ne [string]$_.checksum_live_after_dump } | ForEach-Object { $_.name })
    Add-Result 'CHECKSUM TABLE vs manifest' $(if ($vsManifestSum.Count) { 'FAIL' } else { 'PASS' }) $(if ($vsManifestSum.Count) { "differ: $($vsManifestSum -join ', ')" } else { 'every non-volatile table equals the checksum taken at backup time' })

    $d = Compare-EnotaryList $live.ForeignKeys $rest.ForeignKeys
    Add-Result 'Foreign keys' $(if ($d.Pass -and $live.ForeignKeyCount -eq $rest.ForeignKeyCount) { 'PASS' } else { 'FAIL' }) "live $($live.ForeignKeyCount), restored $($rest.ForeignKeyCount); definitions incl. ON UPDATE/DELETE $(if ($d.Pass) { 'identical' } else { Show-Diff $d })"
    $d = Compare-EnotaryList $live.Indexes $rest.Indexes
    Add-Result 'Indexes' $(if ($d.Pass -and $live.IndexCount -eq $rest.IndexCount) { 'PASS' } else { 'FAIL' }) "live $($live.IndexCount), restored $($rest.IndexCount); columns $(if ($d.Pass) { 'identical' } else { Show-Diff $d })"
    $d = Compare-EnotaryList $live.Columns $rest.Columns
    Add-Result 'Column definitions' $(if ($d.Pass) { 'PASS' } else { 'FAIL' }) $(if ($d.Pass) { "$($rest.Columns.Count) columns identical (type, null, default, extra, charset, collation)" } else { Show-Diff $d })
    $d = Compare-EnotaryList $live.TableOptions $rest.TableOptions
    Add-Result 'Table engine/row format/collation' $(if ($d.Pass) { 'PASS' } else { 'FAIL' }) $(if ($d.Pass) { 'identical' } else { Show-Diff $d })
    $d = Compare-EnotaryList $live.Migrations $rest.Migrations
    Add-Result 'Migrations rows' $(if ($d.Pass) { 'PASS' } else { 'FAIL' }) $(if ($d.Pass) { "$($rest.Migrations.Count) rows identical (id, name, batch)" } else { Show-Diff $d })

    # Triggers: live definitions come from the .TRG files (the app account cannot see them via SQL).
    $liveTriggers = Get-EnotaryTriggerFiles $DataDir $SourceDatabase
    $liveTrig = @($liveTriggers | ForEach-Object { "$($_.Name)|$($_.Table)|$($_.Timing)|$($_.Event)|$($_.ActionOrder)|$($_.BodyMd5)" } | Sort-Object)
    $manTrig = @($manifest.triggers | ForEach-Object { "$($_.name)|$($_.table)|$($_.timing)|$($_.event)|$($_.actionOrder)|$($_.bodyMd5)" } | Sort-Object)
    $d = Compare-EnotaryList $liveTrig $rest.Triggers
    $d2 = Compare-EnotaryList $manTrig $rest.Triggers
    Add-Result 'Triggers (count, table, timing, event, order, body md5)' $(if ($d.Pass -and $d2.Pass) { 'PASS' } else { 'FAIL' }) "live .TRG $($liveTrig.Count), manifest $($manTrig.Count), restored $($rest.Triggers.Count); $(if ($d.Pass -and $d2.Pass) { 'all bodies identical' } else { (Show-Diff $d) + ' ' + (Show-Diff $d2) })"
    $definers = @($rest.TriggerDefiners | ForEach-Object { $_.Split('|')[1] } | Sort-Object -Unique)
    Add-Result 'Restored trigger definer' 'INFO' "$($definers -join ', ') (DEFINER stripped on purpose; live definer is $(@($liveTriggers | ForEach-Object Definer | Sort-Object -Unique) -join ', '))"

    # ---- 5. Do the restored triggers fire? (restored database only) ----------------------------------
    Note "Trigger firing tests in $Target only"
    function Test-Refused([string]$Check, [string]$Sql, [string]$Message) {
        if ($Sql -notmatch "``$Target``\.") { throw "Internal error: firing test is not qualified with $Target." }
        $r = Invoke-EnotarySql -OptionFile $optionFile -Database $Target -Sql "START TRANSACTION; $Sql; ROLLBACK;" -AllowError
        Note "  $Check -> exit $($r.ExitCode): $($r.Error)"
        $ok = ($r.ExitCode -ne 0 -and $r.Error -match '\(45000\)' -and $r.Error.Contains($Message))
        Add-Result "Trigger fires: $Check" $(if ($ok) { 'PASS' } else { 'FAIL' }) $(if ($r.Error) { ($r.Error -replace '^ERROR ', '') } else { 'NOT refused (statement succeeded, rolled back)' })
    }
    function Get-Scalar([string]$Sql) { return [string](@(Get-EnotaryRows $optionFile $Sql $Target)[0]) }
    $auditId = Get-Scalar "SELECT MIN(id) FROM ``$Target``.audit_logs"
    $eventId = Get-Scalar "SELECT MIN(id) FROM ``$Target``.verification_events"
    $adminId = Get-Scalar "SELECT MIN(id) FROM ``$Target``.users WHERE was_admin_at IS NOT NULL"
    $auditBefore = Get-Scalar "SELECT CONCAT(COUNT(*), '/', IFNULL(SUM(CRC32(CONCAT_WS('|', id, action, IFNULL(model_id,0)))),0)) FROM ``$Target``.audit_logs"
    if ($auditId -and $auditId -ne 'NULL') {
        Test-Refused 'UPDATE audit_logs' "UPDATE ``$Target``.audit_logs SET action = CONCAT('restore-probe-', id) WHERE id = $auditId" 'Audit entries cannot be changed.'
        Test-Refused 'DELETE audit_logs' "DELETE FROM ``$Target``.audit_logs WHERE id = $auditId" 'Audit entries cannot be deleted.'
    } else { Add-Result 'Trigger fires: audit_logs' 'FAIL' 'audit_logs is empty in the restored copy; nothing to test against' }
    if ($eventId -and $eventId -ne 'NULL') {
        Test-Refused 'UPDATE verification_events' "UPDATE ``$Target``.verification_events SET reason = 'restore-probe' WHERE id = $eventId" 'Verification history cannot be changed.'
        Test-Refused 'DELETE verification_events' "DELETE FROM ``$Target``.verification_events WHERE id = $eventId" 'Verification history cannot be deleted.'
    } else { Add-Result 'Trigger fires: verification_events' 'FAIL' 'verification_events is empty in the restored copy' }
    if ($adminId -and $adminId -ne 'NULL') {
        Test-Refused 'DELETE former-admin user' "DELETE FROM ``$Target``.users WHERE id = $adminId" 'A current or former administrator account cannot be deleted from the database.'
    } else { Add-Result 'Trigger fires: former admin' 'FAIL' 'no user with was_admin_at in the restored copy' }
    $auditAfter = Get-Scalar "SELECT CONCAT(COUNT(*), '/', IFNULL(SUM(CRC32(CONCAT_WS('|', id, action, IFNULL(model_id,0)))),0)) FROM ``$Target``.audit_logs"
    Add-Result 'Restored audit_logs unchanged after probes' $(if ($auditBefore -eq $auditAfter) { 'PASS' } else { 'FAIL' }) "$auditBefore -> $auditAfter"

    $exitCode = if (@($results | Where-Object { $_.Status -eq 'FAIL' }).Count) { 1 } else { 0 }
} catch {
    Note "ERROR: $($_.Exception.Message) (line $($_.InvocationInfo.ScriptLineNumber))"
    Add-Result 'Run' 'FAIL' $_.Exception.Message
    $exitCode = 1
} finally {
    # ---- 6. Clean up -------------------------------------------------------------------------------
    if ($created -and $optionFile) {
        if ($KeepRestoredDatabase) { Note "Keeping $Target as requested. Drop it when done." }
        else {
            try {
                $null = Invoke-EnotarySql -OptionFile $optionFile -Sql "DROP DATABASE ``$Target``"
                $still = [int](@(Get-EnotaryRows $optionFile "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$Target'")[0])
                Note "DROP DATABASE ``$Target`` -> $(if ($still) { 'STILL PRESENT' } else { 'dropped' })"
                if ($still) { $exitCode = 1 }
            } catch { Note "Could not drop ${Target}: $($_.Exception.Message)"; $exitCode = 1 }
        }
    }
    Remove-EnotaryOptionFile $optionFile
    if ($tempDir -and (Test-Path -LiteralPath $tempDir)) { Remove-Item -LiteralPath $tempDir -Recurse -Force }
    try {
        $reportDir = Join-Path $BackupRoot 'restore-tests'
        if (-not (Test-Path -LiteralPath $reportDir)) { $null = New-Item -ItemType Directory -Path $reportDir; Protect-EnotaryPath $reportDir }
        $reportFile = Join-Path $reportDir ("$(if ($Package) { Split-Path $Package -Leaf } else { 'unknown' })-restore-test-$((Get-Date).ToString('yyyyMMdd-HHmmss', $inv)).txt")
        $body = @("Restore test of $Package", "Result: $(if ($exitCode -eq 0) { 'PASSED' } else { 'FAILED' })", '', 'Log:') + $log + @('', 'Checks:') +
            @($results | ForEach-Object { "{0,-5} {1}: {2}" -f $_.Status, $_.Check, $_.Detail })
        Write-EnotaryUtf8File $reportFile (($body -join "`r`n") + "`r`n")
        Write-Host "Report: $reportFile"
    } catch { Write-Host "Could not write the report: $($_.Exception.Message)" -ForegroundColor Yellow }
    Write-Host $(if ($exitCode -eq 0) { 'RESTORE TEST PASSED' } else { 'RESTORE TEST FAILED' }) -ForegroundColor $(if ($exitCode -eq 0) { 'Green' } else { 'Red' })
}
exit $exitCode
