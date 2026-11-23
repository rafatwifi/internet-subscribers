@echo off
cd /d "%~dp0"
start "ISP print" /min python "%~dp0local_print_agent.py"
