@echo off
setlocal EnableExtensions
cd /d "%~dp0"

echo Removing WhatsApp Gateway auto-start...

schtasks /Delete /TN "WiFiNetSalesWhatsAppGateway" /F >nul 2>&1
echo [OK] Scheduled task removed (if existed).

set "LNK=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\WiFiNetSales-WhatsApp-Gateway.lnk"
if exist "%LNK%" (
  del /f /q "%LNK%"
  echo [OK] Startup shortcut removed.
) else (
  echo [..] No Startup shortcut found.
)

echo.
echo Auto-start removed. Gateway will not start after reboot unless you run it manually.
pause
