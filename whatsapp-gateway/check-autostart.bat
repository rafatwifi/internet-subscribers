@echo off
setlocal EnableExtensions
cd /d "%~dp0"

echo Time now: %date% %time%
echo Folder: %CD%
echo.

echo === Scheduled task ===
schtasks /Query /TN "WiFiNetSalesWhatsAppGateway" /V /FO LIST 2>&1

echo.
echo === Decode Last Result ===
for /f "tokens=1,* delims=:" %%a in ('schtasks /Query /TN "WiFiNetSalesWhatsAppGateway" /V /FO LIST ^| findstr /C:"Last Result"') do (
  set "LR=%%b"
)
set "LR=%LR: =%"
if "%LR%"=="0" echo Meaning: SUCCESS
if "%LR%"=="267011" echo Meaning: Task never ran yet ^(wait for next login / run install again^)
if "%LR%"=="267009" echo Meaning: Task is currently running
if "%LR%"=="1" echo Meaning: Failed - check autostart.log

echo.
echo === Startup shortcut ===
set "LNK=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\WiFiNetSales-WhatsApp-Gateway.lnk"
if exist "%LNK%" (echo Found: %LNK%) else (echo MISSING shortcut)

echo.
echo === Autostart log ===
if exist "%CD%\autostart.log" (type "%CD%\autostart.log") else (echo No autostart.log - run install-autostart.bat)

echo.
echo === Listening on 3001? ===
netstat -ano | findstr ":3001"
if errorlevel 1 echo NOT listening

echo.
pause
