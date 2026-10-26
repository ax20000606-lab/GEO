@echo off
chcp 65001 >nul
echo.
echo ============================================
echo   GEO 示范页（100 分样板）启动中...
echo ============================================
echo.

docker rm -f geo-demo >nul 2>&1

docker run -d --name geo-demo ^
  --network geoflow-prod-net ^
  -p 3443:443 ^
  -v "D:\GEOFlow\geo-audit\demo:/app:ro" ^
  --workdir /app ^
  --restart unless-stopped ^
  python:3.12-slim python serve.py 443 >nul

if errorlevel 1 (
  echo [失败] 启动不了，先确认 Docker Desktop 在运行。
  pause
  exit /b 1
)

echo 等待服务就绪...
timeout /t 4 /nobreak >nul

echo.
echo 已启动。
echo.
echo   浏览器预览：https://localhost:3443/
echo                 ^(证书警告点「高级」-^>「继续前往」^)
echo.
echo   GEOFlow 诊断：http://localhost:18080/geo_admin/geo-audit
echo                 https://www.binsis.cn/   -^> 49 分
echo                 https://geo-demo/        -^> 100 分
echo.
pause
