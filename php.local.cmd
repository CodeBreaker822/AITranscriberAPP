@echo off
REM Local php proxy (use inside the repo). Avoids global php conflicts.
"%~dp0php\php.exe" %*
