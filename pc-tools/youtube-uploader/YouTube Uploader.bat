@echo off
title YouTube Uploader
cd /d "%~dp0"
where py >nul 2>nul
if %errorlevel%==0 (
    py -3 yt_uploader.py %*
) else (
    where python >nul 2>nul
    if %errorlevel%==0 (
        python yt_uploader.py %*
    ) else (
        echo Python is not installed. Get it from https://www.python.org/downloads/
        echo During setup, tick "Add python.exe to PATH".
    )
)
echo.
pause
