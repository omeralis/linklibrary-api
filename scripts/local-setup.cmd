@echo off
rem Double-click or run from cmd: runs the LinkLibrary local setup & verification script.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0local-setup.ps1"
pause
