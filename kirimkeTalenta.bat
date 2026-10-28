@echo off
setlocal EnableExtensions DisableDelayedExpansion
rem Compatibility entry point: keep this filename/path in the existing Task Scheduler action.
if not exist "%~dp0bin\run-talenta.ps1" (
    echo ERROR: Missing bin\run-talenta.ps1. Deploy the complete integration application.
    exit /b 2
)
if "%~1"=="" goto run
if /I "%~1"=="--preview" goto preview
if /I "%~1"=="--manual" goto manual
echo Usage: kirimkeTalenta.bat [--preview^|--manual]
exit /b 64

:run
powershell.exe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "%~dp0bin\run-talenta.ps1"
exit /b %ERRORLEVEL%

:preview
powershell.exe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "%~dp0bin\run-talenta.ps1" -Preview
exit /b %ERRORLEVEL%

:manual
powershell.exe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "%~dp0bin\run-talenta.ps1" -Manual
exit /b %ERRORLEVEL%
