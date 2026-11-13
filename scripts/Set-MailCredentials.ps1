<#
.SYNOPSIS
  Writes Gmail SMTP credentials into the local .env without the App Password
  ever appearing in chat, command-line arguments, shell history, or logs.

.DESCRIPTION
  Workflow:
    1. Create a Google App Password: https://myaccount.google.com/apppasswords
       (requires 2-Step Verification on the account).
    2. Run from the project root, naming the Gmail account that owns the App Password
       (the Gmail account that sends the site's mail):
         powershell -ExecutionPolicy Bypass -File scripts\Set-MailCredentials.ps1 -Username <your-gmail-address>
    3. When the script asks, copy the 16-character App Password to the clipboard
       (spaces are fine) and press any key. Copy it AFTER pasting the command:
       pasting the command line replaces whatever was on the clipboard.
    4. The script reads the password from the clipboard, validates it, backs up
       .env to a PRIVATE folder (outside the web root), sets MAIL_MAILER=smtp,
       MAIL_USERNAME, MAIL_PASSWORD and MAIL_FROM_ADDRESS, clears the clipboard,
       and prints PASS/FAIL only. Then run: php artisan config:clear

  Use -DryRun to validate without writing. Use -Mailer log to switch back to the log mailer.
  Use -NoPrompt to skip the "press any key" pause (the clipboard must already hold the App Password).

  Security notes:
    - The password is taken from the clipboard only; it is never a parameter, so it
      never appears in the command line, PSReadLine history, or a transcript.
    - Nothing but PASS/FAIL text is printed; the password variable is never output.
    - The backup is a copy of .env as it was BEFORE this run. If you run the script
      a second time, that backup holds the previously stored App Password; the
      backup folder is private (outside the web root). Delete old backups when
      you rotate the App Password.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)] [string] $Username,
    [string] $Mailer = 'smtp',
    [string] $EnvPath = '',
    [string] $BackupDir = (Join-Path $env:USERPROFILE 'E-Notary-backups\env-backups'),
    [switch] $DryRun,
    [switch] $NoPrompt
)

$ErrorActionPreference = 'Stop'
function Fail([string] $why) { Write-Output "FAIL: $why"; exit 1 }

if ([string]::IsNullOrEmpty($EnvPath)) {
    $scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
    $EnvPath = Join-Path (Split-Path -Parent $scriptDir) '.env'
}
if (-not (Test-Path -LiteralPath $EnvPath)) { Fail ".env not found at $EnvPath" }
if ($Username -notmatch '^[^@\s]+@[^@\s]+\.[^@\s]+$') { Fail 'Username must be the full Gmail address' }
if ($Mailer -notin @('smtp', 'log')) { Fail 'Mailer must be smtp or log' }

$lines = Get-Content -LiteralPath $EnvPath
function SetLine([string[]] $lines, [string] $key, [string] $value) {
    $found = $false
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match "^$key=") { $lines[$i] = "$key=$value"; $found = $true }
    }
    if (-not $found) { $lines += "$key=$value" }
    return $lines
}

$password = $null
if ($Mailer -eq 'smtp') {
    if (-not $NoPrompt) {
        # Pasting this command line overwrites the clipboard, so the App Password must be
        # copied AFTER the command is pasted. Wait here until the user has done that.
        Write-Output "Copy the 16-letter App Password for $Username to the clipboard NOW, then press any key (type nothing)."
        try { $null = [Console]::ReadKey($true) } catch { $null = Read-Host }
    }
    try { $password = (Get-Clipboard -Raw -ErrorAction Stop) } catch { Fail 'clipboard is empty or unreadable' }
    if ($null -eq $password) { Fail 'clipboard is empty' }
    # Normalize in memory only: drop whitespace (incl. non-breaking) and invisible
    # zero-width / joiner / BOM / soft-hyphen characters a browser copy can insert.
    $password = ($password -replace '[\s\u00AD\u200B-\u200D\u2060\uFEFF]', '')
    if ($password -notmatch '^[A-Za-z]{16}$') {
        $count = $password.Length
        $looksLikeCommand = ($password -match 'Set-MailCredentials|ExecutionPolicy')
        $password = $null
        if ($looksLikeCommand) {
            Fail 'clipboard holds this command line, not the App Password; paste the command first, then copy the App Password, then press a key'
        }
        Fail ("clipboard does not hold a 16-letter Google App Password ({0} characters after removing spaces); copy it again" -f $count)
    }
}

if ($DryRun) {
    $password = $null
    Write-Output "PASS (dry run): inputs valid; .env untouched"
    exit 0
}

if ($BackupDir -like 'C:\xampp\htdocs*') { Fail 'backup folder must be outside the web root' }
New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
Copy-Item -LiteralPath $EnvPath -Destination (Join-Path $BackupDir ".env.before-mail-config-$stamp")

$lines = SetLine $lines 'MAIL_MAILER' $Mailer
if ($Mailer -eq 'smtp') {
    $lines = SetLine $lines 'MAIL_HOST' 'smtp.gmail.com'
    $lines = SetLine $lines 'MAIL_PORT' '587'
    $lines = SetLine $lines 'MAIL_SCHEME' 'null'
    $lines = SetLine $lines 'MAIL_USERNAME' $Username
    $lines = SetLine $lines 'MAIL_PASSWORD' $password
    $lines = SetLine $lines 'MAIL_FROM_ADDRESS' $Username
}

$raw = Get-Content -LiteralPath $EnvPath -Raw
$nl = if ($raw -match "`r`n") { "`r`n" } else { "`n" }
$trailing = if ($raw.EndsWith($nl)) { $nl } else { '' }
[System.IO.File]::WriteAllText($EnvPath, (($lines -join $nl) + $trailing), (New-Object System.Text.UTF8Encoding($false)))

Set-Clipboard -Value ' '
$password = $null; $lines = $null; $raw = $null

if ($Mailer -eq 'smtp') {
    Write-Output ("PASS: MAIL_MAILER=smtp; credentials written for {0}; backup saved in {1}" -f $Username, $BackupDir)
} else {
    Write-Output ("PASS: MAIL_MAILER=log; no credentials written or changed; backup saved in {0}" -f $BackupDir)
}
Write-Output 'Next: php artisan config:clear ; php artisan enotary:mail-status'
