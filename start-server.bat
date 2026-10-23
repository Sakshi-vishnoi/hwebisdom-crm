@echo off
set "PHP_DIR=%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe"
if exist "%PHP_DIR%\php.exe" (
    set "PATH=%PHP_DIR%;%PATH%"
)
echo ===================================================
echo   Starting Webisdom CRM Server at:
echo   http://localhost:8000
echo ===================================================
echo Press Ctrl+C to stop the server.
php -S localhost:8000
pause
