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
    // Spec 019 — registers the `/broadcasting/auth` endpoint so Echo's
    // private-channel auth POST has somewhere to land. `withRouting(channels: …)`
    // alone only loads the channel definitions; the auth route lives behind
    // this call.
    ->withBroadcasting(__DIR__.'/../routes/channels.php')
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

        // Trust loopback proxies. Required for cloudflared / ngrok dev
        // tunnels — they terminate TLS and forward plain HTTP from
        // 127.0.0.1 with `X-Forwarded-Proto: https`. Without this,
        // signed URLs (email verification, password reset) verify the
        // signature against the request's http:// URL while it was
        // signed against https://, and every click 403's "Invalid
        // signature." Loopback-only is safe in prod too — anything that
        // can connect from loopback already has direct app access.
        $middleware->trustProxies(at: [
            '127.0.0.1',
            '::1',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
