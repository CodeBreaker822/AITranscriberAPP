@echo off
REM Local composer proxy (use inside repo). Calls the repo-local PHP to run composer.phar
"%~dp0php.local.cmd" composer.phar %*
