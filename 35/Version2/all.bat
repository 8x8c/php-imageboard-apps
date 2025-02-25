@echo off
REM =============================================================
REM Configure the filenames you want to combine into all.txt
REM Just list them all in the FILES variable, separated by spaces
REM For example:
REM set FILES=1.php 2.php 3.php some-other-file.txt
REM =============================================================
set FILES=admin.php chess_template.php config.php functions.php install.php recent.php reply_template.php

REM =============================================================
REM Remove old all.txt if it exists
REM =============================================================
if exist all.txt del all.txt

REM =============================================================
REM Append each file into all.txt
REM =============================================================
for %%F in (%FILES%) do (
    type "%%F" >> all.txt
)

echo All files have been successfully appended into all.txt for you to easily feed to ai 
pause
