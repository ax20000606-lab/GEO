@echo off
set CONTAINER=bge-embed

echo ============================================================
echo   停止本地 bge 向量化服务
echo ============================================================
echo.

docker ps --filter name=%CONTAINER% --format "{{.Names}}" | findstr /x "%CONTAINER%" >nul
if errorlevel 1 goto :notrunning

echo   正在停止 %CONTAINER% ...
docker stop %CONTAINER% >nul
echo   已停止。
echo.
echo   说明：这是"停止"，容器还在。重新启动用「启动bge服务.bat」，
echo         彻底删除容器用： docker rm -f %CONTAINER%
echo.
echo   注意：服务停掉后，GEOFlow 的知识库切片会因拿不到向量而失败。
echo         如果还有切片任务在跑，建议等它跑完再停。
echo.
pause
exit /b 0

:notrunning
echo   容器 %CONTAINER% 没在运行（可能已经停了）。
echo.
pause
exit /b 0
