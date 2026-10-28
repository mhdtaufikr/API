@echo off
setlocal EnableExtensions DisableDelayedExpansion
powershell.exe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "%~dp0bin\start-local.ps1"
exit /b %ERRORLEVEL%
