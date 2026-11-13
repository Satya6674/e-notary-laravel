# Shared helpers for the E-Notary database scripts (Backup-EnotaryLocal.ps1, Test-BackupRestore.ps1,
# Test-CleanInstallSchema.ps1). Dot-source it:   . (Join-Path $PSScriptRoot 'lib\EnotaryDb.ps1')
#
# Design rules every helper follows:
#   * Credentials are read from a .env file into memory and handed to the MariaDB client tools only
#     through a temporary option file (--defaults-extra-file) whose ACL allows the current Windows user
#     alone. They are never printed, logged, put on a command line or written into a package.
#   * SQL is passed with -e and must not contain double quotes (Windows PowerShell 5.1 mangles them
#     in native arguments). Identifiers use backticks, strings use single quotes.
#   * Every helper throws on failure, so callers with $ErrorActionPreference = 'Stop' fail loudly.
#   * Works in Windows PowerShell 5.1 and PowerShell 7.

$script:EnotaryMysqlBin = 'C:\xampp\mysql\bin'

function Set-EnotaryMysqlBin([string]$Path) {
    foreach ($tool in @('mysql.exe', 'mysqldump.exe')) {
        if (-not (Test-Path -LiteralPath (Join-Path $Path $tool))) { throw "$tool was not found in $Path." }
    }
    $script:EnotaryMysqlBin = $Path
}

function Get-EnotaryMysqlTool([string]$Name) { return (Join-Path $script:EnotaryMysqlBin $Name) }

# Native tool output is decoded with the console encoding; make it UTF-8 so names compare exactly.
function Set-EnotaryUtf8Console {
    try { [Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false) } catch { }
}

# Reads DB_* keys from a .env file. Returns a hashtable; the caller must never print DB_PASSWORD.
function Read-EnotaryDbEnv([string]$EnvFile) {
    if (-not (Test-Path -LiteralPath $EnvFile)) { throw "The env file $EnvFile was not found." }
    $settings = @{}
    foreach ($line in Get-Content -LiteralPath $EnvFile) {
        if ($line -match '^\s*(DB_CONNECTION|DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD)\s*=\s*(.*)$') {
            $value = $Matches[2].Trim()
            if ($value.Length -ge 2 -and (($value[0] -eq '"' -and $value[-1] -eq '"') -or ($value[0] -eq "'" -and $value[-1] -eq "'"))) {
                $value = $value.Substring(1, $value.Length - 2)
            }
            $settings[$Matches[1]] = $value
        }
    }
    if (-not $settings['DB_HOST']) { $settings['DB_HOST'] = '127.0.0.1' }
    if (-not $settings['DB_PORT']) { $settings['DB_PORT'] = '3306' }
    return $settings
}

# Grants Full Control to the current Windows user only and removes every inherited entry.
# Directories get (OI)(CI) so files created inside later inherit the same single entry.
function Protect-EnotaryPath([string]$Path) {
    $who = "$($env:USERDOMAIN)\$($env:USERNAME)"
    $isDir = (Get-Item -LiteralPath $Path -Force).PSIsContainer
    $grant = if ($isDir) { "${who}:(OI)(CI)F" } else { "${who}:F" }
    $out = & icacls $Path /inheritance:r /grant:r $grant 2>&1
    if ($LASTEXITCODE -ne 0) { throw "icacls could not restrict ${Path}: $out" }
    # Remove any explicit entry for another principal that /grant:r left in place.
    $acl = Get-Acl -LiteralPath $Path
    foreach ($rule in @($acl.Access)) {
        if ($rule.IdentityReference.Value -ne $who) {
            $null = & icacls $Path /remove:g "$($rule.IdentityReference.Value)" 2>&1
            if ($LASTEXITCODE -ne 0) { throw "icacls could not remove $($rule.IdentityReference.Value) from $Path." }
        }
    }
}

# True when every access rule on the path belongs to the current user and nothing is inherited.
function Test-EnotaryPathPrivate([string]$Path) {
    $who = "$($env:USERDOMAIN)\$($env:USERNAME)"
    $acl = Get-Acl -LiteralPath $Path
    if (-not $acl.AreAccessRulesProtected) { return $false }
    foreach ($rule in @($acl.Access)) { if ($rule.IdentityReference.Value -ne $who) { return $false } }
    return (@($acl.Access).Count -gt 0)
}

# Writes a temporary [client] option file. Returns its path. Remove it with Remove-EnotaryOptionFile.
function New-EnotaryOptionFile([hashtable]$Settings, [string]$Directory) {
    if (-not $Settings['DB_USERNAME']) { throw 'No DB_USERNAME in the env file.' }
    if (-not (Test-Path -LiteralPath $Directory)) { $null = New-Item -ItemType Directory -Path $Directory -Force }
    $path = Join-Path $Directory ("client-" + [guid]::NewGuid().ToString('N') + '.cnf')
    # Create empty, lock it down, and only then write the secret into it.
    [System.IO.File]::WriteAllText($path, '')
    Protect-EnotaryPath $path
    $escape = { param($v) ([string]$v).Replace('\', '\\').Replace('"', '\"') }
    $text = "[client]`r`nuser=`"$(& $escape $Settings['DB_USERNAME'])`"`r`npassword=`"$(& $escape $Settings['DB_PASSWORD'])`"`r`n" +
            "host=$($Settings['DB_HOST'])`r`nport=$($Settings['DB_PORT'])`r`n"
    [System.IO.File]::WriteAllText($path, $text, (New-Object System.Text.UTF8Encoding($false)))
    return $path
}

function Remove-EnotaryOptionFile([string]$Path) {
    if ($Path -and (Test-Path -LiteralPath $Path)) {
        # Overwrite before deleting so the secret does not linger in the freed clusters' cache.
        try { [System.IO.File]::WriteAllText($Path, ('x' * 256)) } catch { }
        Remove-Item -LiteralPath $Path -Force
    }
}

# Runs SQL with mysql.exe in batch mode. Returns [pscustomobject]@{ ExitCode; Rows; Error }.
# Without -AllowError a non-zero exit throws. Rows are tab-separated strings (mysql -B escaping).
function Invoke-EnotarySql {
    param(
        [Parameter(Mandatory)] [string]$OptionFile,
        [Parameter(Mandatory)] [string]$Sql,
        [string]$Database,
        [switch]$AllowError
    )
    if ($Sql.Contains('"')) { throw 'Internal error: SQL passed to Invoke-EnotarySql must not contain double quotes.' }
    $toolArgs = @("--defaults-extra-file=$OptionFile", '--default-character-set=utf8mb4', '-N', '-B')
    if ($Database) { $toolArgs += "--database=$Database" }
    $toolArgs += @('-e', $Sql)
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'   # native stderr must not become a terminating error here
    try {
        $output = & (Get-EnotaryMysqlTool 'mysql.exe') @toolArgs 2>&1
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $previous }
    $rows = @($output | Where-Object { $_ -isnot [System.Management.Automation.ErrorRecord] } | ForEach-Object { [string]$_ })
    $err = (@($output | Where-Object { $_ -is [System.Management.Automation.ErrorRecord] } | ForEach-Object { $_.ToString() }) -join "`n")
    if ($code -ne 0 -and -not $AllowError) { throw "mysql.exe failed (exit $code): $err" }
    return [pscustomobject]@{ ExitCode = $code; Rows = $rows; Error = $err }
}

# Convenience: rows only. NOTE: PowerShell unrolls a one-element array on return, so callers must
# always wrap the call in @( ) before indexing.
function Get-EnotaryRows([string]$OptionFile, [string]$Sql, [string]$Database) {
    return @((Invoke-EnotarySql -OptionFile $OptionFile -Sql $Sql -Database $Database).Rows)
}

# Feeds a .sql file to mysql.exe on stdin (handles DELIMITER, big files). Throws on failure.
function Import-EnotarySqlFile([string]$OptionFile, [string]$Database, [string]$File, [string]$ErrorLog) {
    $argLine = "`"--defaults-extra-file=$OptionFile`" --default-character-set=utf8mb4 `"--database=$Database`""
    $p = Start-Process -FilePath (Get-EnotaryMysqlTool 'mysql.exe') -ArgumentList $argLine -RedirectStandardInput $File `
        -RedirectStandardError $ErrorLog -RedirectStandardOutput "$ErrorLog.out" -NoNewWindow -Wait -PassThru
    $err = if (Test-Path -LiteralPath $ErrorLog) { (Get-Content -LiteralPath $ErrorLog -Raw) } else { '' }
    Remove-Item -LiteralPath "$ErrorLog.out" -Force -ErrorAction SilentlyContinue
    if ($p.ExitCode -ne 0) { throw "Importing $File into $Database failed (exit $($p.ExitCode)): $err" }
}

function Get-EnotarySha256([string]$Path) { return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant() }

function Get-EnotaryMd5Text([string]$Text) {
    $md5 = [System.Security.Cryptography.MD5]::Create()
    try { return (($md5.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($Text)) | ForEach-Object { $_.ToString('x2') }) -join '') }
    finally { $md5.Dispose() }
}

function Write-EnotaryUtf8File([string]$Path, [string]$Text) {
    [System.IO.File]::WriteAllText($Path, $Text, (New-Object System.Text.UTF8Encoding($false)))
}

# --- Trigger definitions straight from the server's data directory -------------------------------
# MariaDB keeps each table's triggers in <datadir>\<db>\<table>.TRG and one <trigger>.TRN per trigger.
# These files are readable by the Windows user even when the SQL account (enotary_app) has no TRIGGER
# privilege, so SHOW TRIGGERS shows it nothing. Format (one key per line):
#   triggers='stmt1' 'stmt2' ...   sql_modes=n n   definers='u@h' ...   client_cs_names=...
#   connection_cl_names=...   db_cl_names=...   created=...
# Strings are single-quoted with backslash escapes (\n \r \0 \\ \').

function ConvertFrom-EnotaryTrgList([string]$Value) {
    $items = New-Object System.Collections.Generic.List[string]
    $i = 0
    while ($i -lt $Value.Length) {
        $c = $Value[$i]
        if ($c -eq "'") {
            $sb = New-Object System.Text.StringBuilder
            $i++
            while ($i -lt $Value.Length -and $Value[$i] -ne "'") {
                if ($Value[$i] -eq '\' -and $i + 1 -lt $Value.Length) {
                    $i++
                    switch -CaseSensitive ([string]$Value[$i]) {
                        'n' { [void]$sb.Append("`n") }
                        'r' { [void]$sb.Append("`r") }
                        't' { [void]$sb.Append("`t") }
                        '0' { [void]$sb.Append([char]0) }
                        default { [void]$sb.Append($Value[$i]) }
                    }
                } else { [void]$sb.Append($Value[$i]) }
                $i++
            }
            if ($i -ge $Value.Length) { throw 'Unterminated string in a .TRG file.' }
            $items.Add($sb.ToString()); $i++
        } elseif ($c -eq ' ') { $i++ }
        else {
            $j = $Value.IndexOf(' ', $i); if ($j -lt 0) { $j = $Value.Length }
            $items.Add($Value.Substring($i, $j - $i)); $i = $j
        }
    }
    return , $items.ToArray()
}

# Returns one object per trigger, in creation (action) order within each table.
# Body = the text after FOR EACH ROW, which is what information_schema.TRIGGERS.ACTION_STATEMENT holds.
function Get-EnotaryTriggerFiles([string]$DataDir, [string]$Database) {
    $dir = Join-Path $DataDir $Database
    if (-not (Test-Path -LiteralPath $dir)) { throw "The data directory $dir was not found." }
    $result = @()
    foreach ($trg in @(Get-ChildItem -LiteralPath $dir -Filter '*.TRG' -File | Sort-Object Name)) {
        $text = [System.IO.File]::ReadAllText($trg.FullName, [System.Text.Encoding]::UTF8)
        $keys = @{}
        foreach ($line in ($text -split "`n")) {
            $line = $line.TrimEnd("`r")
            if ($line -match '^([a-z_]+)=(.*)$') { $keys[$Matches[1]] = $Matches[2] }
        }
        if ($keys['TYPE'] -ne 'TRIGGERS') { throw "$($trg.Name) is not a TRIGGERS file." }
        $statements = ConvertFrom-EnotaryTrgList $keys['triggers']
        $modes = ConvertFrom-EnotaryTrgList $keys['sql_modes']
        $definers = ConvertFrom-EnotaryTrgList $keys['definers']
        $clientCs = ConvertFrom-EnotaryTrgList $keys['client_cs_names']
        $connCl = ConvertFrom-EnotaryTrgList $keys['connection_cl_names']
        $dbCl = ConvertFrom-EnotaryTrgList $keys['db_cl_names']
        foreach ($list in @($modes, $definers, $clientCs, $connCl, $dbCl)) {
            if ($list.Count -ne $statements.Count) { throw "$($trg.Name): the per-trigger lists have different lengths." }
        }
        $orderBySlot = @{}
        for ($k = 0; $k -lt $statements.Count; $k++) {
            $stmt = $statements[$k]
            $rx = '(?s)^CREATE\s+DEFINER=(?<definer>\S+)\s+TRIGGER\s+`?(?<name>[A-Za-z0-9_]+)`?\s+(?<timing>BEFORE|AFTER)\s+(?<event>INSERT|UPDATE|DELETE)\s+ON\s+`?(?<table>[A-Za-z0-9_]+)`?\s+FOR\s+EACH\s+ROW\s+(?<body>.*)$'
            $m = [regex]::Match($stmt, $rx)
            if (-not $m.Success) { throw "$($trg.Name): trigger #$($k + 1) could not be parsed." }
            $slot = "$($m.Groups['timing'].Value) $($m.Groups['event'].Value)"
            $orderBySlot[$slot] = 1 + [int]$orderBySlot[$slot]
            $body = $m.Groups['body'].Value
            $result += [pscustomobject]@{
                Name           = $m.Groups['name'].Value
                Table          = $m.Groups['table'].Value
                Timing         = $m.Groups['timing'].Value
                Event          = $m.Groups['event'].Value
                ActionOrder    = $orderBySlot[$slot]
                Definer        = $definers[$k]
                DefinerClause  = $m.Groups['definer'].Value
                SqlModeNumber  = [long]$modes[$k]
                ClientCharset  = $clientCs[$k]
                ConnectionColl = $connCl[$k]
                DbCollation    = $dbCl[$k]
                Statement      = $stmt
                Body           = $body
                BodyMd5        = Get-EnotaryMd5Text $body
                StatementNoDefinerMd5 = Get-EnotaryMd5Text ($stmt -replace '^CREATE\s+DEFINER=\S+\s+TRIGGER', 'CREATE TRIGGER')
                SourceFile     = $trg.Name
            }
        }
        if ($result | Where-Object { $_.SourceFile -eq $trg.Name -and $_.Table -ne $trg.BaseName }) {
            throw "$($trg.Name) holds a trigger for another table."
        }
    }
    # Each trigger must have its .TRN pointing back at the same table.
    foreach ($t in $result) {
        $trn = Join-Path $dir "$($t.Name).TRN"
        if (-not (Test-Path -LiteralPath $trn)) { throw "Trigger $($t.Name) has no .TRN file." }
        $trnText = [System.IO.File]::ReadAllText($trn)
        if ($trnText -notmatch "(?m)^trigger_table=$([regex]::Escape($t.Table))\s*$") { throw "$($t.Name).TRN does not point at table $($t.Table)." }
    }
    $trnCount = @(Get-ChildItem -LiteralPath $dir -Filter '*.TRN' -File).Count
    if ($trnCount -ne $result.Count) { throw "Found $trnCount .TRN files but $($result.Count) triggers in .TRG files." }
    return , $result
}

# Turns a numeric sql_mode (as stored in .TRG) into names, using the server itself (session-only SET).
function Resolve-EnotarySqlMode([string]$OptionFile, [long]$Number) {
    $rows = @(Get-EnotaryRows $OptionFile "SET SESSION sql_mode = $Number; SELECT @@SESSION.sql_mode;")
    if ($rows.Count -ne 1 -or -not $rows[0]) { throw "Could not resolve sql_mode $Number." }
    return [string]$rows[0]
}

# Builds triggers.sql text in mysqldump style: each trigger with its own sql_mode and character-set
# context, DEFINER kept exactly as on the server. Load it AFTER the data dump.
function New-EnotaryTriggersSql($Triggers, [string]$Database, [hashtable]$ModeNames, [string]$Generated) {
    $sb = New-Object System.Text.StringBuilder
    [void]$sb.AppendLine("-- Trigger definitions for database $Database, rebuilt byte-for-byte from the server's .TRG files.")
    [void]$sb.AppendLine("-- Generated: $Generated   Triggers: $($Triggers.Count)")
    [void]$sb.AppendLine('-- Load this file AFTER the data dump, into the same database (it has no USE statement).')
    [void]$sb.AppendLine('-- The DEFINER clauses are the original ones. Creating a trigger for another account needs SUPER')
    [void]$sb.AppendLine('-- (or SET USER); a restricted account must strip "DEFINER=..." first (Test-BackupRestore.ps1 does).')
    [void]$sb.AppendLine('/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;')
    [void]$sb.AppendLine('/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;')
    [void]$sb.AppendLine('/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;')
    [void]$sb.AppendLine('/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE */;')
    [void]$sb.AppendLine('DELIMITER ;;')
    $n = 0
    foreach ($t in $Triggers) {
        $n++
        if ($t.Statement.Contains(';;')) { throw "Trigger $($t.Name) contains ';;' and cannot be written with DELIMITER ;;." }
        [void]$sb.AppendLine("-- [$n/$($Triggers.Count)] $($t.Name): $($t.Timing) $($t.Event) ON $($t.Table), order $($t.ActionOrder), definer $($t.Definer), body md5 $($t.BodyMd5), db collation $($t.DbCollation)")
        [void]$sb.AppendLine("/*!50003 SET SESSION SQL_MODE='$($ModeNames[[string]$t.SqlModeNumber])' */;;")
        [void]$sb.AppendLine("/*!50003 SET character_set_client = $($t.ClientCharset) */;;")
        [void]$sb.AppendLine("/*!50003 SET character_set_results = $($t.ClientCharset) */;;")
        [void]$sb.AppendLine("/*!50003 SET collation_connection = $($t.ConnectionColl) */;;")
        [void]$sb.AppendLine("$($t.Statement) ;;")
    }
    [void]$sb.AppendLine('DELIMITER ;')
    [void]$sb.AppendLine('/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;')
    [void]$sb.AppendLine('/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;')
    [void]$sb.AppendLine('/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;')
    [void]$sb.AppendLine('/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;')
    [void]$sb.AppendLine("-- Triggers completed: $($Triggers.Count)")
    return $sb.ToString().Replace("`r`n", "`n")
}

# --- Schema / data snapshot for comparisons --------------------------------------------------------
# Every element is a sorted string array so two snapshots compare with Compare-Object.
# NULL is shown as <NULL>. Data parts (row counts, CHECKSUM TABLE) only with -WithData.
function Get-EnotarySnapshot {
    param([string]$OptionFile, [string]$Database, [switch]$WithData, [switch]$WithServerTriggers)
    $q = { param($sql) Get-EnotaryRows $OptionFile $sql $null }
    $db = $Database.Replace("'", "''")
    $snap = [ordered]@{}
    $snap.Tables = @(& $q "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$db' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")
    $snap.Views = @(& $q "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$db' AND TABLE_TYPE<>'BASE TABLE' ORDER BY TABLE_NAME")
    $snap.TableOptions = @(& $q "SELECT CONCAT_WS('|', TABLE_NAME, ENGINE, ROW_FORMAT, TABLE_COLLATION) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$db' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")
    $snap.Columns = @(& $q ("SELECT CONCAT_WS('|', TABLE_NAME, LPAD(ORDINAL_POSITION,3,'0'), COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, IFNULL(COLUMN_DEFAULT,'<NULL>'), " +
        "EXTRA, IFNULL(CHARACTER_SET_NAME,'<NULL>'), IFNULL(COLLATION_NAME,'<NULL>'), COLUMN_KEY) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$db' ORDER BY TABLE_NAME, ORDINAL_POSITION"))
    $snap.Indexes = @(& $q ("SELECT CONCAT_WS('|', TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, IFNULL(SUB_PART,'<NULL>'), INDEX_TYPE) " +
        "FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='$db' ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"))
    $snap.IndexCount = [int](@(& $q "SELECT COUNT(DISTINCT TABLE_NAME, INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='$db'")[0])
    $snap.ForeignKeys = @(& $q ("SELECT CONCAT_WS('|', k.TABLE_NAME, k.CONSTRAINT_NAME, GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION), k.REFERENCED_TABLE_NAME, " +
        "GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.ORDINAL_POSITION), r.UPDATE_RULE, r.DELETE_RULE) FROM information_schema.KEY_COLUMN_USAGE k " +
        "JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME " +
        "WHERE k.CONSTRAINT_SCHEMA='$db' AND k.REFERENCED_TABLE_NAME IS NOT NULL GROUP BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME, r.UPDATE_RULE, r.DELETE_RULE ORDER BY 1"))
    $snap.ForeignKeyCount = [int](@(& $q "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='$db'")[0])
    $snap.CheckConstraints = @(& $q "SELECT CONCAT_WS('|', TABLE_NAME, CONSTRAINT_NAME, CHECK_CLAUSE) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='$db' ORDER BY 1")
    if ($snap.Tables -contains 'migrations') {
        $snap.Migrations = @(& $q "SELECT CONCAT_WS('|', id, migration, batch) FROM ``$($Database)``.migrations ORDER BY id")
    } else { $snap.Migrations = @() }
    if ($WithServerTriggers) {
        $snap.Triggers = @(& $q ("SELECT CONCAT_WS('|', TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_ORDER, MD5(ACTION_STATEMENT)) " +
            "FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$db' ORDER BY TRIGGER_NAME"))
        $snap.TriggerDefiners = @(& $q "SELECT CONCAT_WS('|', TRIGGER_NAME, DEFINER, SQL_MODE) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$db' ORDER BY TRIGGER_NAME")
    }
    if ($WithData -and $snap.Tables.Count -gt 0) {
        $union = ($snap.Tables | ForEach-Object { "SELECT '$_', COUNT(*) FROM ``$($Database)``.``$_``" }) -join ' UNION ALL '
        $snap.RowCounts = @(& $q $union | ForEach-Object { $_ -replace "`t", '|' } | Sort-Object)
        $list = ($snap.Tables | ForEach-Object { "``$($Database)``.``$_``" }) -join ', '
        $snap.Checksums = @(& $q "CHECKSUM TABLE $list" | ForEach-Object { ($_ -replace "`t", '|') -replace '^[^.|]+\.', '' } | Sort-Object)
    }
    return $snap
}

# Compares two string arrays; returns @{ Pass; OnlyLeft; OnlyRight }.
function Compare-EnotaryList($Left, $Right) {
    $l = @($Left); $r = @($Right)
    # Case-sensitive on purpose: a default of 'Pending' vs 'pending' is a real difference.
    $onlyL = @($l | Where-Object { $r -cnotcontains $_ })
    $onlyR = @($r | Where-Object { $l -cnotcontains $_ })
    return [pscustomobject]@{ Pass = ($onlyL.Count -eq 0 -and $onlyR.Count -eq 0 -and $l.Count -eq $r.Count); OnlyLeft = $onlyL; OnlyRight = $onlyR }
}
