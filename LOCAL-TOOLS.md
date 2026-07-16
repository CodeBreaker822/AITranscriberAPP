This repository includes small local shims to make invoking the bundled `node` and `php` easy and avoid colliding with global installs.

Usage
- From PowerShell or CMD while inside the repo root, run:

```
.\npm.local.cmd run tauri:build
.\npm.local.cmd run tauri:dev
.\php.local.cmd artisan migrate
.\php.local.cmd --version
```

Enable no-`.
- In PowerShell, run the helper once per session to prepend the repo root to `PATH`:

```
.\enable-local-tools.ps1
```

After running the helper you can call the local shims without the `.` and backslash, for example:

```
npm.local run tauri:build
php.local --version
```

Composer
- This repo includes `composer.phar`. Use the provided shim:

```
composer.local install
composer.local update
```

The `composer.local.cmd` wrapper runs `composer.phar` using the repo-local PHP so you don't need a global Composer install.

Notes
- The helper updates `PATH` only for the current PowerShell session. Add the command to your PowerShell profile if you want it persistent.

Files
- [npm.local.cmd](npm.local.cmd#L1): proxies to `node\npm.cmd` in the repo.
- [php.local.cmd](php.local.cmd#L1): proxies to `php\php.exe` in the repo.
- [enable-local-tools.ps1](enable-local-tools.ps1#L1): prepends repo root to PATH for the session.
