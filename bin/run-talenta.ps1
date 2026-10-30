param([switch]$Preview, [switch]$Manual)
$ErrorActionPreference = 'Stop'
if ($Preview -and $Manual) { throw 'Choose Preview or Manual, not both.' }
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$console = Join-Path $projectRoot 'bin\console.php'
$log = Join-Path $projectRoot 'storage\scheduler-console.log'
$php = $null
try {
    if (-not (Test-Path -LiteralPath $console -PathType Leaf)) { throw 'Missing bin\console.php.' }
    if ($env:MKM_TALENTA_PHP_EXE) {
        $candidates = @($env:MKM_TALENTA_PHP_EXE)
    } else {
        $fromPath = Get-Command php.exe -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty Source
        # Prefer the production XAMPP runtime whose network/CA path is verified.
        # PATH remains a fallback so an unrelated developer PHP cannot silently
        # replace the runtime used by Task Scheduler or an operator shell.
        $candidates = @('C:\xampp\php\php.exe', $fromPath, 'C:\php\php.exe') | Where-Object { $_ } | Select-Object -Unique
    }
    foreach ($candidate in $candidates) {
        if (-not (Test-Path -LiteralPath $candidate -PathType Leaf)) { continue }
        & $candidate -r 'exit(PHP_VERSION_ID >= 70400 ? 0 : 1);' *> $null
        if ($LASTEXITCODE -eq 0) { $php = $candidate; break }
    }
    if (-not $php) { throw 'PHP 7.4+ was not found. Configure MKM_TALENTA_PHP_EXE with a supported php.exe path.' }
    if (-not (Test-Path -LiteralPath (Join-Path $projectRoot 'storage') -PathType Container)) { throw 'Missing writable storage directory.' }
    $stamp = [DateTimeOffset]::Now.ToString('o')
    $mode = if ($Preview) { 'preview' } elseif ($Manual) { 'manual' } else { 'scheduled' }
    Add-Content -LiteralPath $log -Value "[$stamp] kirimkeTalenta.bat mode=$mode php=$php" -Encoding UTF8
    Push-Location -LiteralPath $projectRoot
    try {
        $phpArgs = @($console, 'talenta:scheduler')
        if ($Preview) { $phpArgs += '--preview' }
        if ($Manual) { $phpArgs += '--manual' }
        # Preserve native stderr/output and the actual process exit code under Windows PowerShell.
        $ErrorActionPreference = 'Continue'
        & $php @phpArgs 2>&1 | ForEach-Object {
            $line = $_.ToString()
            [IO.File]::AppendAllText($log, $line + [Environment]::NewLine, [Text.UTF8Encoding]::new($false))
            Write-Output $line
        }
        $result = $LASTEXITCODE
        $ErrorActionPreference = 'Stop'
        Add-Content -LiteralPath $log -Value "[$([DateTimeOffset]::Now.ToString('o'))] exit_code=$result" -Encoding UTF8
    } finally { Pop-Location }
    exit $result
} catch {
    [Console]::Error.WriteLine('Talenta BAT launcher failed: ' + $_.Exception.Message)
    exit 1
}
