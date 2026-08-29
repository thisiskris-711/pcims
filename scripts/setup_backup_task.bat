@echo off
echo Setting up PCIMS Auto-Backup Task...
echo.

set PHP_BIN=C:\xampp\php\php-win.exe
set SCRIPT_PATH=C:\xampp\htdocs\pcims\scripts\auto_backup.php
set TASK_NAME=PCIMS_AutoBackup

if not exist "%PHP_BIN%" (
    echo Error: PHP executable not found at %PHP_BIN%.
    echo Please update the PHP_BIN path in this script.
    pause
    exit /b 1
)

if not exist "%SCRIPT_PATH%" (
    echo Error: Backup script not found at %SCRIPT_PATH%.
    pause
    exit /b 1
)

echo Registering Windows Scheduled Task...
schtasks /create /tn "%TASK_NAME%" /tr "\"%PHP_BIN%\" \"%SCRIPT_PATH%\"" /sc hourly /f

if %ERRORLEVEL% EQU 0 (
    echo.
    echo Successfully registered task: %TASK_NAME%
    echo The script will run every hour in the background.
    echo You can configure the exact backup time and retention rules in the web application.
) else (
    echo.
    echo Failed to register the scheduled task. Please run this script as Administrator.
)

pause
