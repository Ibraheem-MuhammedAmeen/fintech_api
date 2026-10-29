<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // By default Laravel redirects logged-out users to a web "login" page.
        // This API has no such page, so a request without a token (and
        // without "Accept: application/json") crashed with a 500 error
        // "Route [login] not defined". API requests must never redirect.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') || ! Route::has('login')
                ? null
                : route('login')
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every error under /api/* is returned as JSON (401, 404, 422, ...),
        // even if the client forgot the "Accept: application/json" header.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();