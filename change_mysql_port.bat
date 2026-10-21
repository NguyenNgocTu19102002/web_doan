@echo off
echo ========================================
echo DOI PORT MYSQL XAMPP SANG 3308
echo ========================================
echo.

echo [BUOC 1] DUNG MYSQL TRONG XAMPP
echo Vui long mo XAMPP Control Panel va STOP MySQL truoc!
echo.
pause

echo.
echo [BUOC 2] CAP NHAT FILE my.ini
echo.

set INI_FILE=C:\xampp\mysql\bin\my.ini
set BACKUP_FILE=C:\xampp\mysql\bin\my.ini.backup

REM Backup file cu
if exist "%INI_FILE%" (
    echo Backup file my.ini...
    copy "%INI_FILE%" "%BACKUP_FILE%"
    echo [OK] Da backup thanh my.ini.backup
) else (
    echo [LOI] Khong tim thay file my.ini!
    pause
    exit /b 1
)

echo.
echo Thay doi port 3306 thanh 3308...

REM Thay doi port trong file my.ini
powershell -Command "(Get-Content '%INI_FILE%') -replace 'port\s*=\s*3306', 'port=3308' | Set-Content '%INI_FILE%'"
powershell -Command "(Get-Content '%INI_FILE%') -replace 'port=3306', 'port=3308' | Set-Content '%INI_FILE%'"

echo [OK] Da cap nhat file my.ini
echo.

echo ========================================
echo HOAN TAT CAU HINH!
echo ========================================
echo.
echo BUOC TIEP THEO:
echo 1. Mo XAMPP Control Panel
echo 2. Click START ben canh MySQL
echo 3. Kiem tra MySQL chay o port 3308:
echo    netstat -ano | findstr 3308
echo 4. Test ket noi: http://localhost:8080/tntt/index.php
echo.
echo Neu gap loi, khoi phuc file backup:
echo    copy "%BACKUP_FILE%" "%INI_FILE%"
echo.
pause
