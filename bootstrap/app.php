<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // GitHub webhooks don't carry session cookies / CSRF tokens —
        // they're authenticated via `X-Hub-Signature-256` (verified in
        // GitHubWebhookController). Excluding here prevents Laravel from
        // 419'ing the unauthenticated POST before our signature check
        // runs.
        $middleware->preventRequestForgery(except: [
            'webhooks/github',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
