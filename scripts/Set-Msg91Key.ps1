<#
.SYNOPSIS
  Safely writes a new MSG91 Authkey into the local .env without the key ever
  appearing in chat, command-line arguments, shell history, or logs.

.DESCRIPTION
  Workflow:
    1. In the MSG91 dashboard, click "Copy" on the newly created Authkey
       (it is now in the Windows clipboard).
    2. Run:  powershell -ExecutionPolicy Bypass -File scripts\Set-Msg91Key.ps1
    3. The script reads the clipboard, validates the value, backs up .env to a
       PRIVATE folder (outside the web root), replaces only the
       MSG91_AUTH_KEY line, clears the clipboard, and prints PASS/FAIL only.

  The script never prints the key. Use -DryRun to validate without writing.
#>
[CmdletBinding()]
param(
    [string] $EnvPath = '',
    [string] $BackupDir = (Join-Path $env:USERPROFILE 'E-Notary-backups\env-backups'),
    [switch] $DryRun
)

$ErrorActionPreference = 'Stop'

function Fail([string] $why) { Write-Output "FAIL: $why"; exit 1 }

# Resolve the project .env relative to this script (scripts\ -> project root).
if ([string]::IsNullOrEmpty($EnvPath)) {
    $scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
    $EnvPath = Join-Path (Split-Path -Parent $scriptDir) '.env'
}

if (-not (Test-Path -LiteralPath $EnvPath)) { Fail ".env not found at $EnvPath" }

# 1. Read the candidate key from the clipboard (never from arguments).
$candidate = $null
try { $candidate = (Get-Clipboard -Raw -ErrorAction Stop) } catch { Fail 'clipboard is empty or unreadable' }
if ($null -eq $candidate) { Fail 'clipboard is empty' }
$candidate = $candidate.Trim()

# 2. Validate shape only (MSG91 authkeys are alphanumeric, typically 20-40 chars).
if ($candidate.Length -lt 20 -or $candidate.Length -gt 64) { Fail ("clipboard value has unexpected length (" + $candidate.Length + "); copy the Authkey again") }
if ($candidate -notmatch '^[A-Za-z0-9]+$') { Fail 'clipboard value contains characters that are not part of an Authkey' }

# 3. Compare with the current value (must differ, must not be empty).
$lines = Get-Content -LiteralPath $EnvPath
$idx = -1
for ($i = 0; $i -lt $lines.Count; $i++) { if ($lines[$i] -match '^MSG91_AUTH_KEY=') { $idx = $i; break } }
if ($idx -lt 0) { Fail 'MSG91_AUTH_KEY line not found in .env' }
$current = ($lines[$idx] -replace '^MSG91_AUTH_KEY=', '').Trim().Trim('"')
if ($current -eq $candidate) { Fail 'clipboard value is identical to the key already in .env (nothing changed)' }

if ($DryRun) {
    $len = $candidate.Length
    $candidate = $null; $current = $null
    Write-Output ("PASS (dry run): clipboard holds a well-formed key of " + $len + " chars that differs from the current one; .env untouched")
    exit 0
}

# 4. Backup .env to a private folder (outside C:\xampp\htdocs).
if ($BackupDir -like 'C:\xampp\htdocs*') { Fail 'backup folder must be outside the web root' }
New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backup = Join-Path $BackupDir (".env.before-msg91-rotation-" + $stamp)
Copy-Item -LiteralPath $EnvPath -Destination $backup

# 5. Replace only the MSG91_AUTH_KEY line; keep every other line byte-for-byte.
$lines[$idx] = 'MSG91_AUTH_KEY=' + $candidate
$raw = Get-Content -LiteralPath $EnvPath -Raw
$nl = if ($raw -match "`r`n") { "`r`n" } else { "`n" }
$trailing = if ($raw.EndsWith($nl)) { $nl } else { '' }
[System.IO.File]::WriteAllText($EnvPath, (($lines -join $nl) + $trailing), (New-Object System.Text.UTF8Encoding($false)))

# 6. Wipe the clipboard and in-memory copies.
Set-Clipboard -Value ' '
$candidate = $null; $current = $null; $lines = $null; $raw = $null

Write-Output ("PASS: MSG91_AUTH_KEY line replaced in .env; backup saved to " + $backup)
Write-Output 'Next: php artisan config:clear ; php artisan enotary:sms-status'
