param([switch]$Preview)
$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
try {
    $fromPath = Get-Command php.exe -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty Source
    $candidates = if ($env:MKM_HRMS_PHP_EXE) { @($env:MKM_HRMS_PHP_EXE) } else { @('C:\xampp\php\php.exe',$fromPath,'C:\php\php.exe') | Where-Object { $_ } | Select-Object -Unique }
    $php = $null
    foreach ($candidate in $candidates) {
        if (-not (Test-Path -LiteralPath $candidate -PathType Leaf)) { continue }
        & $candidate -r 'exit(PHP_VERSION_ID >= 70400 ? 0 : 1);' *> $null
        if ($LASTEXITCODE -eq 0) { $php = $candidate; break }
    }
    if (-not $php) { throw 'PHP 7.4+ not found. Set MKM_HRMS_PHP_EXE.' }
    $command = if ($Preview) { 'hrms:preview' } else { 'hrms:export' }
    Push-Location -LiteralPath $projectRoot
    try { & $php (Join-Path $projectRoot 'bin\console.php') $command; $result = $LASTEXITCODE } finally { Pop-Location }
    exit $result
} catch { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }
