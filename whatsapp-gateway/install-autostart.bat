@echo off
setlocal EnableExtensions
cd /d "%~dp0"

echo ============================================
echo  WhatsApp Gateway - Auto Start on Reboot
echo ============================================
echo.
echo Hidden start after login + at Windows start.
echo Folder: %CD%
echo.

set "STARTUP=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup"
set "LNK=%STARTUP%\WiFiNetSales-WhatsApp-Gateway.lnk"
set "VBS=%~dp0start-hidden.vbs"

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$ws = New-Object -ComObject WScript.Shell; $s = $ws.CreateShortcut('%LNK%'); $s.TargetPath = 'wscript.exe'; $s.Arguments = '\"%VBS%\"'; $s.WorkingDirectory = '%~dp0'; $s.WindowStyle = 7; $s.Description = 'WhatsApp Gateway (hidden)'; $s.Save(); Write-Host '[OK] Startup shortcut'"

echo.

set "TASK=WiFiNetSalesWhatsAppGateway"
schtasks /Delete /TN "%TASK%" /F >nul 2>&1
schtasks /Create /TN "%TASK%" /TR "wscript.exe \"%VBS%\"" /SC ONLOGON /DELAY 0000:30 /F
if errorlevel 1 (echo [WARN] ONLOGON task failed) else (echo [OK] ONLOGON task)

REM Also try at boot (needs admin). Safe to ignore if denied.
schtasks /Delete /TN "%TASK%Boot" /F >nul 2>&1
schtasks /Create /TN "%TASK%Boot" /TR "wscript.exe \"%VBS%\"" /SC ONSTART /DELAY 0001:00 /RL HIGHEST /F >nul 2>&1
if errorlevel 1 (
  echo [WARN] ONSTART task needs Admin — ONLOGON still works after login
) else (
  echo [OK] ONSTART boot task
)

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$names = @('WiFiNetSalesWhatsAppGateway','WiFiNetSalesWhatsAppGatewayBoot'); foreach($n in $names){ $t = Get-ScheduledTask -TaskName $n -ErrorAction SilentlyContinue; if ($t) { $s = $t.Settings; $s.DisallowStartIfOnBatteries = $false; $s.StopIfGoingOnBatteries = $false; $s.StartWhenAvailable = $true; $s.ExecutionTimeLimit = 'PT0S'; $s.RestartCount = 3; $s.RestartInterval = 'PT1M'; Set-ScheduledTask -InputObject $t | Out-Null; Write-Host ('[OK] ' + $n + ' hardened') } }"

echo.
echo --- Start hidden NOW ---
wscript.exe "%VBS%"
timeout /t 4 /nobreak >nul

echo.
echo === autostart.log ===
if exist "%CD%\autostart.log" (type "%CD%\autostart.log") else (echo No log)
echo.
echo === port 3001 ===
netstat -ano | findstr ":3001"
echo.
echo After reboot: gateway should listen on 3001 without a black window.
echo Settings page will show QR when WhatsApp is not linked.
echo.
pause
