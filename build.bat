@echo off
setlocal enabledelayedexpansion

echo === K-one Allocator Desktop Builder ===
echo.

set "SOURCE=D:\K-one\allocator"
set "TARGET=D:\K-one-Allocator-Desktop"
set "TOOLS=%TARGET%\tools"
set "PHP=C:\xampp\php\php.exe"

:: Step 1: Clean target (preserve .git, tools, assets, manifest)
echo [1/5] Cleaning target directory...
if exist "%TARGET%\classes" rmdir /s /q "%TARGET%\classes"
if exist "%TARGET%\vendor" rmdir /s /q "%TARGET%\vendor"
if exist "%TARGET%\api.php" del /q "%TARGET%\api.php"
if exist "%TARGET%\download.php" del /q "%TARGET%\download.php"
if exist "%TARGET%\print_picklist.php" del /q "%TARGET%\print_picklist.php"
if exist "%TARGET%\index.php" del /q "%TARGET%\index.php"
if exist "%TARGET%\composer.json" del /q "%TARGET%\composer.json"
if exist "%TARGET%\composer.lock" del /q "%TARGET%\composer.lock"

:: Step 2: Copy application files
echo [2/5] Copying application files from source...
xcopy "%SOURCE%\classes" "%TARGET%\classes\" /E /I /Y /Q
copy /Y "%SOURCE%\api.php" "%TARGET%\api.php" >nul
copy /Y "%SOURCE%\download.php" "%TARGET%\download.php" >nul
copy /Y "%SOURCE%\print_picklist.php" "%TARGET%\print_picklist.php" >nul
copy /Y "%SOURCE%\index.php" "%TARGET%\index.php" >nul
copy /Y "%SOURCE%\composer.json" "%TARGET%\composer.json" >nul

:: Step 3: Verify source integrity (drift detection)
echo [3/5] Verifying source file integrity...
if exist "%TARGET%\source_manifest.txt" (
    "%PHP%" -r "$m=file_get_contents('%TARGET%/source_manifest.txt'); $ok=true; foreach(explode(PHP_EOL,$m) as $l){if(!trim($l))continue; $p=strpos($l,'  '); $h=substr($l,0,$p); $f=substr($l,$p+2); $cf=hash_file('sha256',$f); if($h!==$cf){echo \"DRIFT DETECTED: $f\n\"; $ok=false;}} if($ok)echo \"All source files verified.\n\"; else echo \"WARNING: Source drift detected! Build may be stale.\n\";"
) else (
    echo WARNING: No source manifest found. Skipping integrity check.
)

:: Step 4: Install Composer dependencies
echo [4/5] Installing Composer dependencies...
if not exist "%TARGET%\vendor" (
    if exist "%TOOLS%\composer.phar" (
        "%PHP%" "%TOOLS%\composer.phar" install --working-dir="%TARGET%" --no-dev --optimize-autoloader
    ) else (
        echo ERROR: composer.phar not found at %TOOLS%\composer.phar
        echo Please install Composer: https://getcomposer.org/download
        exit /b 1
    )
) else (
    echo vendor/ already exists. Skipping. Delete it to reinstall.
)

:: Step 5: Verify build
echo [5/5] Verifying build...
if not exist "%TARGET%\vendor\autoload.php" (
    echo ERROR: vendor/autoload.php not found after install!
    exit /b 1
)
if not exist "%TARGET%\classes\Allocator.php" (
    echo ERROR: classes/Allocator.php not found!
    exit /b 1
)

echo.
echo === Build complete ===
echo Desktop project ready at: %TARGET%
echo.
echo To test: php -S localhost:8080 -t "%TARGET%"
echo.
pause
