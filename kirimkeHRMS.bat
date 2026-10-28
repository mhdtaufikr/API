@echo off
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0bin\run-hrms.ps1" %*
exit /b %ERRORLEVEL%
