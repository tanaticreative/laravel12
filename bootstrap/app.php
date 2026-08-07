<?php

use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\UniformNotFoundResponse;
use App\Http\Middleware\UniformServerErrorResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Prepended so it runs before validation and model binding, which are
        // the two places that would otherwise answer a browser instead of a
        // client.
        //
        // The two response rewriters sit outermost for the same reason: an
        // exception thrown anywhere deeper is rendered by the routing pipeline
        // and travels back out through them, which is the only position from
        // which they can normalise it.
        $middleware->api(prepend: [
            ForceJsonResponse::class,
            UniformNotFoundResponse::class,
            UniformServerErrorResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
