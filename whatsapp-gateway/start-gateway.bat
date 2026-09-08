@echo off
cd /d "%~dp0"
chcp 65001 >nul
set PATH=C:\Program Files\nodejs;%PATH%
REM If antivirus HTTPS scan breaks WhatsApp TLS, keep this =1
set WA_TLS_INSECURE=1
echo Starting WhatsApp gateway...
echo Autostart tip: run install-autostart.bat once (hidden on reboot).
node index.js
pause
