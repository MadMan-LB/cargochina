@echo off
REM Run CLMS database migrations
REM Use XAMPP PHP to ensure MySQL driver is available
c:\xampp\php\php.exe "%~dp0backend\migrations\run.php"
pause
