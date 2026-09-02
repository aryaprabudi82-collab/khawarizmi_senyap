<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Rute autentikasi memakai bahasa Indonesia; bawaan Laravel menunjuk
        // rute bernama login yang tidak ada di sini.
        $middleware->redirectGuestsTo(fn () => route("masuk"));
        $middleware->redirectUsersTo(fn () => route("beranda"));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
