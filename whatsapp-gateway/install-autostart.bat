@echo off
setlocal EnableExtensions
cd /d "%~dp0"

echo ============================================
echo  WhatsApp Gateway - Hidden Auto Start
echo ============================================
echo.
echo Runs in background - NO window on screen.
echo Folder: %CD%
echo.

REM --- 1) Startup shortcut -^> wscript + VBS (fully silent) ---
set "STARTUP=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup"
set "LNK=%STARTUP%\WiFiNetSales-WhatsApp-Gateway.lnk"
set "VBS=%~dp0start-hidden.vbs"

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$ws = New-Object -ComObject WScript.Shell; $s = $ws.CreateShortcut('%LNK%'); $s.TargetPath = 'wscript.exe'; $s.Arguments = '\"%VBS%\"'; $s.WorkingDirectory = '%~dp0'; $s.WindowStyle = 7; $s.Description = 'WhatsApp Gateway (hidden)'; $s.Save(); Write-Host '[OK] Hidden Startup shortcut'"

echo.

REM --- 2) Scheduled task -^> wscript (no cmd window) ---
set "TASK=WiFiNetSalesWhatsAppGateway"

schtasks /Delete /TN "%TASK%" /F >nul 2>&1
schtasks /Create /TN "%TASK%" /TR "wscript.exe \"%VBS%\"" /SC ONLOGON /DELAY 0000:45 /F

if errorlevel 1 (
  echo [WARN] Task create failed
) else (
  echo [OK] Hidden task created
)

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$t = Get-ScheduledTask -TaskName 'WiFiNetSalesWhatsAppGateway' -ErrorAction SilentlyContinue; if ($t) { $s = $t.Settings; $s.DisallowStartIfOnBatteries = $false; $s.StopIfGoingOnBatteries = $false; $s.StartWhenAvailable = $true; $s.ExecutionTimeLimit = 'PT0S'; Set-ScheduledTask -InputObject $t | Out-Null; Write-Host '[OK] Battery restrictions off' }"

echo.
echo --- Start hidden NOW (you should see NO window) ---
wscript.exe "%VBS%"
timeout /t 3 /nobreak >nul

echo.
echo === autostart.log ===
if exist "%CD%\autostart.log" (type "%CD%\autostart.log") else (echo No log)
echo.
echo === port 3001 ===
netstat -ano | findstr ":3001"
echo.
echo If 3001 is LISTENING and no black window opened = success.
echo Do NOT use start-gateway.bat for daily use (that one shows a window).
echo Use only this auto-start (hidden).
echo.
pause
