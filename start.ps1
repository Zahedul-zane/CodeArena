# CodeArena — Quick Start Script (Windows PowerShell)
# Run: .\start.ps1

Write-Host ""
Write-Host "  CodeArena — Full Stack Contest Platform" -ForegroundColor Cyan
Write-Host "  ========================================" -ForegroundColor DarkCyan
Write-Host ""

# Check PHP
if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    Write-Host "  PHP not found. Please ensure PHP 8.1+ is installed and in your PATH." -ForegroundColor Red
    exit 1
}

# Check Node
if (-not (Get-Command node -ErrorAction SilentlyContinue)) {
    Write-Host "  Node.js not found. Please install Node.js from https://nodejs.org" -ForegroundColor Red
    exit 1
}

Write-Host "  [1/3] Checking Node.js frontend dependencies..." -ForegroundColor Yellow
if (-not (Test-Path "$PSScriptRoot\frontend\node_modules")) {
    Push-Location "$PSScriptRoot\frontend"
    cmd /c "npm install --silent"
    Pop-Location
}

Write-Host "  [2/3] Starting PHP backend on port 5000..." -ForegroundColor Yellow
$backendProc = Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd '$PSScriptRoot\backend'; php server.php" -PassThru

Start-Sleep -Seconds 2

Write-Host "  [3/3] Starting Node.js frontend on port 3000..." -ForegroundColor Yellow
$frontendProc = Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd '$PSScriptRoot\frontend'; node server.js" -PassThru

Start-Sleep -Seconds 2

Write-Host ""
Write-Host "  CodeArena is live!" -ForegroundColor Green
Write-Host ""
Write-Host "  Frontend    : http://localhost:3000" -ForegroundColor Cyan
Write-Host "  Backend API : http://localhost:5000/api" -ForegroundColor Cyan
Write-Host "  Excel DB    : backend/data/contest.xlsx" -ForegroundColor Green
Write-Host ""
Write-Host "  Default Admin Account:" -ForegroundColor White
Write-Host "    Username  : admin" -ForegroundColor White
Write-Host "    Password  : admin123" -ForegroundColor White
Write-Host "    Admin Nav : Click 'Admin & DB' in navbar after login" -ForegroundColor White
Write-Host ""

Start-Process "http://localhost:3000"
