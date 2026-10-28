param([ValidateRange(1024,65535)][int]$Port = 8088)
$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$url = "http://127.0.0.1:$Port/machine-simulator.php"
function Test-CompatibleSimulator {
    try {
        $page = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 2
        $version = [regex]::Match($page.Content, 'name="mkm-php-version" content="([0-9.]+)"').Groups[1].Value
        return $page.StatusCode -eq 200 -and $version -ne '' -and [version]$version -ge [version]'7.4'
    } catch { return $false }
}
try {
    if (Test-CompatibleSimulator) { Write-Output "Compatible simulator is already running: $url"; exit 0 }
    if (Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue) { throw "Port $Port is in use by another runtime. No process was stopped." }
    $fromPath = Get-Command php.exe -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty Source
    $candidates = if ($env:MKM_TALENTA_PHP_EXE) { @($env:MKM_TALENTA_PHP_EXE) } else { @($fromPath,'C:\php\php.exe','C:\xampp\php\php.exe') | Where-Object { $_ } | Select-Object -Unique }
    $php = $null
    foreach ($candidate in $candidates) {
        if (-not (Test-Path -LiteralPath $candidate -PathType Leaf)) { continue }
        & $candidate -r 'exit(PHP_VERSION_ID >= 70400 ? 0 : 1);' *> $null
        if ($LASTEXITCODE -eq 0) { $php = $candidate; break }
    }
    if (-not $php) { throw 'PHP 7.4+ not found. Set MKM_TALENTA_PHP_EXE to a compatible php.exe.' }
    $public = Join-Path $projectRoot 'public'
    $router = Join-Path $projectRoot 'bin\router.php'
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $outLog = Join-Path $projectRoot "storage\local-php-$stamp.log"
    $errLog = Join-Path $projectRoot "storage\local-php-$stamp-error.log"
    $arguments = '-S 127.0.0.1:' + $Port + ' -t "' + $public + '" "' + $router + '"'
    $process = Start-Process -FilePath $php -ArgumentList $arguments -WorkingDirectory $projectRoot -WindowStyle Hidden -RedirectStandardOutput $outLog -RedirectStandardError $errLog -PassThru
    for ($attempt=0; $attempt -lt 8; $attempt++) {
        if (Test-CompatibleSimulator) { Write-Output "PHP 7.4+ simulator ready: $url"; exit 0 }
        if ($process.HasExited) { throw "Local server exited. Inspect $errLog" }
        Start-Sleep -Milliseconds 250
    }
    throw "Local server did not become ready. Inspect $errLog"
} catch { [Console]::Error.WriteLine($_.Exception.Message); exit 1 }
