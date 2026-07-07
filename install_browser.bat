@echo off
chcp 65001 >nul
cd /d "%~dp0"

if exist "browsers" (
    set "PLAYWRIGHT_BROWSERS_PATH=%~dp0browsers"
) else (
    set "PLAYWRIGHT_BROWSERS_PATH=%~dp0browsers"
    mkdir "%PLAYWRIGHT_BROWSERS_PATH%"
)

echo 正在安装 Chromium 浏览器到: %PLAYWRIGHT_BROWSERS_PATH%
python -m playwright install chromium
if errorlevel 1 (
    echo 安装失败，请确认已安装 Python 和 playwright
    pause
    exit /b 1
)

echo 浏览器安装完成！
pause
