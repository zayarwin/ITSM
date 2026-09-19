<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Telnet keystrokes (e.g. Enter is "\r\n") must reach validation untouched —
        // the default trimming would reduce whitespace-only keystrokes to "" and then null.
        $middleware->trimStrings(except: ['data']);

        $middleware->alias([
            'idle.timeout' => \App\Http\Middleware\ExpireIdleSanctumToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
