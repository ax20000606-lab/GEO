@echo off
chcp 936 >nul
echo 正在停止 GEO 诊断器（端口 8021）...
for /f "tokens=5" %%a in ('netstat -ano ^| findstr ":8021" ^| findstr "LISTENING"') do (
  taskkill /F /PID %%a >nul 2>&1
  echo 已停止进程 %%a
)
echo 完成。
timeout /t 2 >nul
