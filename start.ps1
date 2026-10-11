# CodeArena — Quick Start Script (Windows PowerShell)
# Run: .\start.ps1

Write-Host ""
Write-Host "  CodeArena — Full Stack Contest Platform" -ForegroundColor Cyan
Write-Host "  ========================================" -ForegroundColor DarkCyan
Write-Host ""

# Check Python
try {
    python --version | Out-Null
} catch {
    Write-Host "  Python not found. Please install Python from https://python.org" -ForegroundColor Red
    exit 1
}

# Check Node
try {
    node --version | Out-Null
} catch {
    Write-Host "  Node.js not found. Please install Node.js from https://nodejs.org" -ForegroundColor Red
    exit 1
}

Write-Host "  [1/4] Installing Python backend dependencies..." -ForegroundColor Yellow
Push-Location "$PSScriptRoot\backend"
python -m pip install -r requirements.txt -q
Pop-Location

Write-Host "  [2/4] Installing Node.js frontend dependencies..." -ForegroundColor Yellow
Push-Location "$PSScriptRoot\frontend"
cmd /c "npm install"
Pop-Location

Write-Host "  [3/4] Starting Python backend on port 5000..." -ForegroundColor Yellow
$backendProc = Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd '$PSScriptRoot\backend'; python app.py" -PassThru

Start-Sleep -Seconds 3

Write-Host "  [4/4] Starting Node.js frontend on port 3000..." -ForegroundColor Yellow
$frontendProc = Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd '$PSScriptRoot\frontend'; node server.js" -PassThru

Start-Sleep -Seconds 2

Write-Host ""
Write-Host "  CodeArena is live!" -ForegroundColor Green
Write-Host ""
Write-Host "  Frontend   : http://localhost:3000" -ForegroundColor Cyan
Write-Host "  Backend API: http://localhost:5000/api" -ForegroundColor Cyan
Write-Host ""
Write-Host "  Default Admin Account:" -ForegroundColor White
Write-Host "    Username : admin" -ForegroundColor White
Write-Host "    Password : admin123" -ForegroundColor White
Write-Host ""

Start-Process "http://localhost:3000"
