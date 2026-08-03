@echo off
REM Stop hidden WhatsApp gateway (kills node listening on 3001)
setlocal EnableExtensions
cd /d "%~dp0"

echo Stopping WhatsApp gateway on port 3001...
for /f "tokens=5" %%p in ('netstat -ano ^| findstr ":3001" ^| findstr "LISTENING"') do (
  echo Killing PID %%p
  taskkill /PID %%p /F >nul 2>&1
)
echo Done. Gateway stopped (still no window needed).
echo %date% %time% STOPPED by stop-gateway.bat>> "autostart.log"
pause
