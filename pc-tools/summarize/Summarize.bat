@echo off
title AI summary - Stream Recorder
cd /d "%~dp0"
set PY=
where py >nul 2>nul && set PY=py -3
if not defined PY where python >nul 2>nul && set PY=python
if not defined PY (
    echo Python is not installed. Get it from https://www.python.org/downloads/
    echo During setup, tick "Add python.exe to PATH".
    pause
    exit /b
)
rem First run (and after an update of requirements.txt): install the packages it needs.
fc /b requirements.txt .installed >nul 2>nul
if errorlevel 1 (
    echo Installing what the summary tool needs (once, a few minutes^)...
    %PY% -m pip install --upgrade -r requirements.txt && copy /y requirements.txt .installed >nul
    echo.
)
%PY% summarize.py %*
echo.
pause
