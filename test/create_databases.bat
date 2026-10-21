@echo off
echo ========================================
echo TAO DATABASE CHO TNTT LAP TRI VA NGOC LAM
echo ========================================
echo.

REM Kiem tra MySQL co chay khong
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe">NUL
if "%ERRORLEVEL%"=="1" (
    echo [!] MySQL chua chay!
    echo [*] Vui long mo XAMPP Control Panel va Start MySQL
    echo.
    pause
    exit /b 1
)

echo [*] MySQL dang chay...
echo.

REM Tao database Lap Tri
echo [1/2] Tao database tntt_lap_tri...
"C:\xampp\mysql\bin\mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS tntt_lap_tri CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if %ERRORLEVEL% EQU 0 (
    echo     [OK] Database tntt_lap_tri da duoc tao!
) else (
    echo     [LOI] Khong the tao database tntt_lap_tri
)
echo.

REM Tao database Ngoc Lam
echo [2/2] Tao database tntt_ngoc_lam...
"C:\xampp\mysql\bin\mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS tntt_ngoc_lam CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if %ERRORLEVEL% EQU 0 (
    echo     [OK] Database tntt_ngoc_lam da duoc tao!
) else (
    echo     [LOI] Khong the tao database tntt_ngoc_lam
)
echo.

REM Kiem tra ket qua
echo ========================================
echo DANH SACH DATABASE:
echo ========================================
"C:\xampp\mysql\bin\mysql.exe" -u root -e "SHOW DATABASES LIKE 'tntt%%';"
echo.

echo ========================================
echo HOAN TAT!
echo ========================================
echo.
echo Buoc tiep theo:
echo 1. Mo trinh duyet: http://localhost:8080/tntt/index.php
echo 2. Neu co file SQL backup, import vao phpMyAdmin
echo 3. Dang nhap voi tai khoan admin
echo.
pause
