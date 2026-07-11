<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();

if ($storagePath = env('APP_STORAGE_PATH')) {
    $app->useStoragePath($storagePath);
}

$processTempPath = env('APP_PROCESS_TEMP_PATH')
    ?: storage_path('framework/process-temp/process-'.getmypid());
if (! is_dir($processTempPath)) {
    @mkdir($processTempPath, 0777, true);
}

foreach (['TMP', 'TEMP', 'TMPDIR'] as $tempVariable) {
    putenv($tempVariable.'='.$processTempPath);
    $_ENV[$tempVariable] = $processTempPath;
    $_SERVER[$tempVariable] = $processTempPath;
}

return $app;
