<?php

use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\VerifyPixelforgeSignature;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Outside the "web" group: Pixelforge sends no session or CSRF token.
        then: function () {
            Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)
                ->middleware(VerifyPixelforgeSignature::class)
                ->name('webhooks.pixelforge');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
