@echo off
cd /d "%~dp0"
echo Stopping any Node on this folder is manual — close start-gateway.bat first.
echo Wiping WhatsApp session (auth)...
if exist "auth" (
  rmdir /s /q "auth"
)
mkdir auth
echo Done. Start start-gateway.bat then scan ONE QR.
pause
