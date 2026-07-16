@echo off
REM Local npm proxy (use inside the repo). Avoids global npm conflicts.
SETLOCAL
"%~dp0node\npm.cmd" %*
ENDLOCAL
