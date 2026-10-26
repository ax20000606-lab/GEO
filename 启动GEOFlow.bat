@echo off
chcp 65001 >nul
REM ============================================================
REM  GEOFlow launcher (prebuilt deployment, web on 18080)
REM
REM  What it does:
REM    1. checks the Docker engine (GEOFlow needs it running)
REM    2. starts the stack with the same compose command as the
REM       deployment note (env-file .env.prod + local override)
REM    3. polls http://127.0.0.1:18080 until it answers, then opens it
REM
REM  Manual fallback (if this script fails, run it in this folder):
REM    docker compose --env-file .env.prod -f docker-compose.prebuilt.yml -f docker-compose.local-override.yml up -d
REM
REM  Admin: http://127.0.0.1:18080/geo_admin/login
REM  Stop : same command with "down" instead of "up -d" (data stays in docker-data\)
REM ============================================================

setlocal
cd /d "D:\GEOFlow\src"

set COMPOSE=docker compose --env-file .env.prod -f docker-compose.prebuilt.yml -f docker-compose.local-override.yml
set URL=http://127.0.0.1:18080

echo [1/3] checking Docker engine ...
docker ps >nul 2>&1
if errorlevel 1 (
    echo.
    echo   [ERROR] Docker engine is not running.
    echo   Start "Docker Desktop" first and wait until it shows "Engine running",
    echo   then double-click this file again.
    echo.
    pause
    exit /b 1
)
echo       engine OK

echo [2/3] starting GEOFlow stack ...
%COMPOSE% up -d
if errorlevel 1 (
    echo   [ERROR] compose failed. Check the output above.
    pause
    exit /b 1
)

echo [3/3] waiting for %URL% ...
set /a tries=0
:wait
curl -s -o nul --max-time 3 %URL% >nul 2>&1
if not errorlevel 1 goto ready
set /a tries+=1
if %tries% GEQ 40 (
    echo       [WARN] still not answering after ~2 minutes.
    echo       Containers may still be initialising. Opening anyway.
    goto open
)
timeout /t 3 /nobreak >nul
goto wait

:ready
echo       site is up

:open
start "" %URL%
echo.
echo Done. Admin console: %URL%/geo_admin/login
timeout /t 3 /nobreak >nul
endlocal
