<#
Run this in PowerShell from the repository root to prepend the repo root
to the current session PATH so you can call `npm.local` and `php.local`
without using `.
#>
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Definition
if (-not $scriptDir) { $scriptDir = (Get-Location).Path }

if (-not (Test-Path $scriptDir)) {
    Write-Error "Repository directory not found: $scriptDir"
    exit 1
}

$env:PATH = "$scriptDir;$env:PATH"
Write-Host "Local tools enabled for this session. Repo root added to PATH: $scriptDir"
Write-Host "You can now run: npm.local run tauri:build (no .\ required)"
