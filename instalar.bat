@echo off
REM ===== Instalacion de Expediente Judicial (Windows) =====
REM Requisitos: PHP 8.2+ (con extensiones zip, gd, mbstring, sqlite3, fileinfo) y Composer.
cd /d "%~dp0"
where php >nul 2>nul || (echo [ERROR] PHP no esta instalado o no esta en el PATH. Instale XAMPP o Laragon. & pause & exit /b 1)
where composer >nul 2>nul || (echo [ERROR] Composer no esta instalado: https://getcomposer.org & pause & exit /b 1)
call composer install --no-interaction || (echo [ERROR] Fallo composer install & pause & exit /b 1)
if not exist .env copy .env.example .env
php artisan key:generate
if not exist database\database.sqlite type nul > database\database.sqlite
php artisan migrate --force --seed
echo.
echo Instalacion terminada. Para datos de demostracion ejecute:  php artisan db:seed --class=DemoSeeder
echo Para iniciar ejecute:  iniciar.bat
pause
