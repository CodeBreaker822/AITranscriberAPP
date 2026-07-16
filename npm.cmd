@echo off
REM Proxy to the repository-local npm (node\npm.cmd)
SETLOCAL
"%~dp0node\npm.cmd" %*
ENDLOCAL
