@echo off
title CodeArena — Starting Platform
echo.
echo ========================================
echo   CodeArena — Full Stack Contest Platform
echo ========================================
echo.

echo [1/4] Checking dependencies...
python --version >nul 2>&1
if errorlevel 1 (
    echo Python not found. Please install Python 3.10+ from python.org.
    pause
    exit /b 1
)

node --version >nul 2>&1
if errorlevel 1 (
    echo Node.js not found. Please install Node.js from nodejs.org.
    pause
    exit /b 1
)

echo [2/4] Installing Python dependencies...
cd /d "%~dp0backend"
pip install -r requirements.txt -q

echo [3/4] Installing Node.js dependencies...
cd /d "%~dp0frontend"
call npm install --silent

echo [4/4] Starting servers...
start "CodeArena Backend (Port 5000)" cmd /k "cd /d "%~dp0backend" && python app.py"
timeout /t 3 >nul

start "CodeArena Frontend (Port 3000)" cmd /k "cd /d "%~dp0frontend" && node server.js"
timeout /t 2 >nul

echo.
echo ========================================
echo   CodeArena is running!
echo   Frontend : http://localhost:3000
echo   Backend  : http://localhost:5000/api
echo   Admin    : admin / admin123
echo ========================================
echo.

start http://localhost:3000
