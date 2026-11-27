@echo off
setlocal EnableDelayedExpansion
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
rem An NVIDIA card makes speech recognition many times faster; its libraries are offered once, for this same Python.
if exist .asked-gpu goto gpudone
where nvidia-smi >nul 2>nul || goto gpudone
%PY% -c "import nvidia.cublas, nvidia.cudnn" >nul 2>nul && goto gpudone
echo You have an NVIDIA graphics card. With its libraries ^(about 1 GB^) speech recognition is many times faster.
set /p GPU="Install them now? [Y/n] "
if /i not "!GPU!"=="n" %PY% -m pip install nvidia-cublas-cu12 "nvidia-cudnn-cu12==9.*"
echo done> .asked-gpu
echo.
:gpudone
rem YouTube downloads need Deno; ffmpeg gives one video file with sound. Offered once each (Windows installer, winget).
where winget >nul 2>nul || goto run
if exist .asked-tools goto run
where deno >nul 2>nul
if errorlevel 1 (
    echo YouTube links need the free program Deno ^(yt-dlp uses it for YouTube^).
    set /p ANS="Install Deno now? [Y/n] "
    if /i not "!ANS!"=="n" (set INST=1& winget install --id DenoLand.Deno -e --accept-source-agreements --accept-package-agreements)
    echo.
)
where ffmpeg >nul 2>nul
if errorlevel 1 (
    echo Optional: ffmpeg joins picture and sound into one video ^(nicer for the player and the scan page^).
    set /p ANS2="Install ffmpeg now? [Y/n] "
    if /i not "!ANS2!"=="n" (set INST=1& winget install --id Gyan.FFmpeg -e --accept-source-agreements --accept-package-agreements)
    echo.
)
echo done> .asked-tools
if defined INST (
    echo Installed. Close this window and start Summarize.bat again, so Windows finds the new program.
    pause
    exit /b
)
:run
%PY% summarize.py %*
echo.
pause
