@echo off
start "Backend" /D "%~dp0backend" cmd /k "php artisan serve"
start "Queue Worker" /D "%~dp0backend" cmd /k "php artisan queue:work --tries=1"
start "Frontend" /D "%~dp0frontend" cmd /k "npm run dev"
start "Middleware" /D "%~dp0middleware" cmd /k ".\venv\Scripts\activate && python main.py"
echo Services started!
