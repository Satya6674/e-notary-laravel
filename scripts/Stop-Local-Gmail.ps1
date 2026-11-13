# Stops the server and worker started by Start-Local-Gmail.ps1, from any window.
# Ending the start script ends both, because they belong to its job object. A recorded
# process is stopped only if it is still that program, as Windows reuses process ids,
# so Live Test and Apache are unaffected.

$ErrorActionPreference = 'Stop'

$stateFile = Join-Path $env:USERPROFILE 'E-Notary-backups\local-gmail\run.json'

if (-not (Test-Path $stateFile)) {
    Write-Host 'Local Gmail is not running.'
    exit 0
}

$state = Get-Content $stateFile -Raw | ConvertFrom-Json
$expected = @{ $state.script = '*Start-Local-Gmail*'; $state.worker = '*enotary-local-gmail*'; $state.server = '*127.0.0.1:8000*' }
if ($state.scheduler) { $expected[$state.scheduler] = '*schedule:work*' }

foreach ($id in $expected.Keys) {
    $process = Get-CimInstance Win32_Process -Filter "ProcessId=$id"
    if ($process -and $process.CommandLine -like $expected[$id]) {
        Stop-Process -Id $id -Force -ErrorAction SilentlyContinue
    }
}

Remove-Item $stateFile -ErrorAction SilentlyContinue

Start-Sleep -Seconds 1

$left = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object { $_.CommandLine -like '*enotary-local-gmail*' -or $_.CommandLine -like '*127.0.0.1:8000*' -or $_.CommandLine -like '*schedule:work*' })
if ($left.Count -gt 0) {
    Write-Host "Still running: process $($left.ProcessId -join ', ')." -ForegroundColor Red
    exit 1
}

Write-Host 'Server, worker and scheduler stopped.' -ForegroundColor Green
