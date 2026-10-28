param(
    [string]$PhpPath = 'C:\php\php.exe',
    [string]$TaskName = 'MKM-Talenta-Scheduled-Export'
)
$ErrorActionPreference = 'Stop'
if ((Get-TimeZone).Id -ne 'SE Asia Standard Time') {
    throw 'Task triggers require the Windows server timezone SE Asia Standard Time (WIB). No timezone was changed.'
}
if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) { throw 'PHP executable was not found.' }
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$console = Join-Path $projectRoot 'bin\console.php'
if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) { throw 'Task name already exists. Review it before changing any existing schedule.' }
$action = New-ScheduledTaskAction -Execute $PhpPath -Argument ('"' + $console + '" talenta:scheduler') -WorkingDirectory $projectRoot
$triggers = @(
    (New-ScheduledTaskTrigger -Daily -At '08:00'),
    (New-ScheduledTaskTrigger -Daily -At '12:00'),
    (New-ScheduledTaskTrigger -Daily -At '18:00')
)
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 10)
$task = New-ScheduledTask -Action $action -Trigger $triggers -Settings $settings -Description 'MKM Talenta: valid unsent attendance events at 08:00, 12:00 and 18:00 WIB. Requires separately approved profile and live-write gates.'
# Register disabled. Enabling this task and production-write flags is a separate deployment action.
$task.Settings.Enabled = $false
Register-ScheduledTask -TaskName $TaskName -InputObject $task | Out-Null
Write-Output "Created disabled task: $TaskName (08:00, 12:00, 18:00 WIB). Existing legacy tasks were not modified."
