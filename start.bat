@echo off
title CodeArena — Starting Platform
echo.
echo ========================================
echo   CodeArena — Full Stack Contest Platform
echo ========================================
echo.

echo [1/3] Checking dependencies...
php -v >nul 2>&1
if errorlevel 1 (
    echo PHP not found. Please ensure PHP 8.1+ is installed and in your PATH.
    pause
    exit /b 1
)

node --version >nul 2>&1
if errorlevel 1 (
    echo Node.js not found. Please install Node.js from nodejs.org.
    pause
    exit /b 1
)

echo [2/3] Checking Node.js frontend dependencies...
if not exist "%~dp0frontend\node_modules" (
    echo Installing frontend packages...
    cd /d "%~dp0frontend"
    call npm install --silent
)

echo [3/3] Starting servers...
start "CodeArena Backend (Port 5000)" cmd /k "cd /d "%~dp0backend" && php server.php"
timeout /t 2 >nul

start "CodeArena Frontend (Port 3000)" cmd /k "cd /d "%~dp0frontend" && node server.js"
timeout /t 2 >nul

echo.
echo ========================================
echo   CodeArena is running!
echo   Frontend : http://localhost:3000
echo   Backend  : http://localhost:5000/api
echo   Excel DB : backend/data/contest.xlsx
echo   Admin    : admin / admin123
echo ========================================
echo.

start http://localhost:3000
