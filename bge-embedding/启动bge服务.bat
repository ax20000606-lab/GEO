@echo off
set CONTAINER=bge-embed
set IMAGE=ai-app
set MODEL_DIR=D:/offline-models/bge-small-zh-v1.5
set CODE_DIR=D:/GEOFlow/bge-embedding
set NET=geoflow-prod-net
set IP=10.88.0.50

echo ============================================================
echo   启动本地 bge 向量化服务（GEOFlow 知识库用）
echo ============================================================
echo.

echo [检查] 镜像 %IMAGE%
docker image inspect %IMAGE% >nul 2>&1
if errorlevel 1 goto :noimage

echo [检查] 模型目录 %MODEL_DIR%
if not exist "%MODEL_DIR%\config.json" goto :nomodel

echo [检查] Docker 网络 %NET%
docker network inspect %NET% >nul 2>&1
if errorlevel 1 goto :nonet

echo [1/3] 移除旧容器（如果存在）
docker rm -f %CONTAINER% >nul 2>&1

echo [2/3] 启动新容器
docker run -d --name %CONTAINER% --network %NET% --ip %IP% --restart unless-stopped -e BGE_MODEL_PATH=/model -e BGE_DEVICE=cpu -e BGE_MODEL_NAME=bge-small-zh-v1.5 -e PYTHONDONTWRITEBYTECODE=1 -v "%MODEL_DIR%:/model:ro" -v "%CODE_DIR%:/svc:ro" -w /svc %IMAGE% python -m uvicorn bge_server:app --host 0.0.0.0 --port 8000 >nul
if errorlevel 1 goto :runfail

echo [3/3] 等待模型加载并做健康检查
set OK=0
for /l %%i in (1,1,20) do call :probe %%i
if "%OK%"=="1" goto :ready

echo.
echo   [失败] 20 次健康检查都没通过。最近日志：
echo ------------------------------------------------------------
docker logs --tail 30 %CONTAINER%
echo ------------------------------------------------------------
pause
exit /b 1

:probe
if "%OK%"=="1" goto :eof
docker exec %CONTAINER% python -c "import urllib.request,json;print(json.load(urllib.request.urlopen('http://127.0.0.1:8000/health',timeout=3))['status'])" > "%TEMP%\bgehealth.txt" 2>nul
set /p H=<"%TEMP%\bgehealth.txt"
if "%H%"=="ok" set OK=1
if "%OK%"=="0" ping -n 4 127.0.0.1 >nul
goto :eof

:ready
echo.
echo ============================================================
echo   服务已就绪
echo ============================================================
echo.
echo   容器名    : %CONTAINER%
echo   内网地址  : http://%IP%:8000
echo.
echo   ---- 在 GEOFlow 后台「AI 配置器」新增模型时填 ----
echo   类型      : embedding
echo   api_url   : http://%IP%:8000
echo   model_id  : bge-small-zh-v1.5
echo   api_key   : bge-local-any-string   ^(本服务不校验，填非空即可^)
echo.
echo   ---- 常用命令 ----
echo   看日志    : docker logs -f %CONTAINER%
echo   停止服务  : 双击「停止bge服务.bat」
echo.
echo ============================================================
pause
exit /b 0

:noimage
echo.
echo   [错误] 找不到镜像 %IMAGE%
echo   这个镜像来自 lfc 客服项目，请先确认：docker images ^| findstr ai-app
echo.
pause
exit /b 1

:nomodel
echo.
echo   [错误] 模型目录不可用：%MODEL_DIR%
echo   请确认该目录里有 config.json 和 pytorch_model.bin
echo.
pause
exit /b 1

:nonet
echo.
echo   [错误] 找不到 Docker 网络 %NET%
echo   请先启动 GEOFlow（双击 D:/GEOFlow/启动GEOFlow.bat），它才会创建这个网络。
echo.
pause
exit /b 1

:runfail
echo.
echo   [错误] 容器启动失败。请手动执行下面这行看报错：
echo   docker run --rm --network %NET% --ip %IP% -v "%MODEL_DIR%:/model:ro" -v "%CODE_DIR%:/svc:ro" -w /svc %IMAGE% python -c "print(1)"
echo.
pause
exit /b 1
