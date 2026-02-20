@echo off
REM Run all CLMS tests
set PHP=c:\xampp\php\php.exe
if not exist "%PHP%" set PHP=php
echo Running all tests...
echo.

set FAILED=0
for %%f in (upload_test search_test smoke_test lifecycle_test consolidation_test item_capture_test suppliers_contact_test tracking_push_idempotency_test tracking_push_retry_test phase2_integration_test production_hardening_test) do (
  echo === %%f ===
  "%PHP%" "%~dp0tests\%%f.php"
  if errorlevel 1 set FAILED=1
  echo.
)

if %FAILED%==0 (
  echo All tests passed.
) else (
  echo Some tests failed.
  exit /b 1
)
echo.
pause
