@echo off
chcp 936 >nul
cd /d "%~dp0"
echo ==========================================
echo   GEO 诊断器 启动中...
echo   访问地址: http://localhost:8021
echo ==========================================
echo.
python server.py
if errorlevel 1 (
  echo.
  echo [错误] 启动失败，请确认已安装 Python 并加入 PATH。
  pause
)
