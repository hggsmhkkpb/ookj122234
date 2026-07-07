@echo off
chcp 65001 >nul
cd /d "%~dp0"

echo ========================================
echo   文章自动总结工具 - 打包脚本
echo ========================================
echo.

echo [1/4] 安装打包依赖...
pip install pyinstaller -q
if errorlevel 1 (
    echo 安装 PyInstaller 失败
    pause
    exit /b 1
)

echo [2/4] 执行 PyInstaller 打包...
pyinstaller --noconfirm --clean article_summarizer.spec
if errorlevel 1 (
    echo 打包失败
    pause
    exit /b 1
)

echo [3/4] 安装 Chromium 浏览器到发布目录...
set "DIST_DIR=%~dp0dist\文章自动总结工具"
set "PLAYWRIGHT_BROWSERS_PATH=%DIST_DIR%\browsers"
if not exist "%DIST_DIR%\browsers" mkdir "%DIST_DIR%\browsers"
set PLAYWRIGHT_BROWSERS_PATH=%DIST_DIR%\browsers
python -m playwright install chromium
if errorlevel 1 (
    echo 浏览器安装失败，请手动运行 install_browser.bat
)

echo [4/4] 复制说明文件...
copy /Y "%~dp0使用说明.txt" "%DIST_DIR%\使用说明.txt" >nul 2>&1

echo.
echo ========================================
echo   打包完成！
echo   输出目录: dist\文章自动总结工具\
echo   运行程序: dist\文章自动总结工具\文章自动总结工具.exe
echo ========================================
pause
