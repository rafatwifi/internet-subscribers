@echo off
REM Called by Task Scheduler / Startup — reliable, always logs
setlocal EnableExtensions
cd /d "%~dp0"

echo %date% %time% ==== start-hidden.cmd ====>> "autostart.log"

set "NODE="
if exist "C:\Program Files\nodejs\node.exe" set "NODE=C:\Program Files\nodejs\node.exe"
if not defined NODE if exist "C:\Program Files (x86)\nodejs\node.exe" set "NODE=C:\Program Files (x86)\nodejs\node.exe"
if not defined NODE for /f "delims=" %%i in ('where node 2^>nul') do (
  set "NODE=%%i"
  goto :have_node
)

:have_node
if not defined NODE (
  echo %date% %time% ERROR: node.exe not found>> "autostart.log"
  exit /b 1
)

echo %date% %time% node=%NODE%>> "autostart.log"
echo %date% %time% launching index.js>> "autostart.log"

start "" /B "%NODE%" "%~dp0index.js"
echo %date% %time% Launched OK>> "autostart.log"
exit /b 0
