@echo off
REM ===== Construye el instalador de Windows (desktop\dist\ExpedienteJudicial-Instalador-*.exe) =====
REM Requisitos en ESTA PC: Node.js 20+, PHP 8.2+ y Composer. Los usuarios finales no necesitan nada.
cd /d "%~dp0desktop"
powershell -NoProfile -ExecutionPolicy Bypass -File construir-windows.ps1
if errorlevel 1 (echo. & echo [ERROR] No se pudo construir el instalador. & pause & exit /b 1)
explorer dist
pause
