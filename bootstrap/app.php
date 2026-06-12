<?php

use App\Http\Middleware\AuthenticateAgent;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

        // GitHub webhooks + the agent telemetry endpoint don't carry
        // session cookies / CSRF tokens — they're authenticated via
        // signed body (GitHub) or `Authorization: Bearer` (agent).
        // Excluding here prevents Laravel from 419'ing the
        // unauthenticated POST before our auth check runs.
        $middleware->preventRequestForgery(except: [
            'webhooks/github',
            'agent/telemetry',
        ]);

        // Spec 027 — bearer-token auth for the agent telemetry endpoint.
        $middleware->alias([
            'agent.auth' => AuthenticateAgent::class,
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
        // Spec 036 — surface uncaught 500-class errors as a friendly
        // Inertia page (`Errors/AppError`) so the user sees a
        // consistent UI with a `Try again` link instead of Laravel's
        // default error template.
        //
        // Strict guards keep Laravel's existing flows intact:
        //   - `APP_DEBUG=true` → Ignition stays (devs need the stack).
        //   - Non-Inertia / non-HTML requests → default handler.
        //   - `ValidationException`/`AuthenticationException`/known
        //     `HttpException` (403/404/419/etc.) → default flow,
        //     which Inertia already maps to the right UX.
        $exceptions->render(function (Throwable $e, Request $request): ?Response {
            if (config('app.debug')) {
                return null;
            }

            if (! $request->header('X-Inertia') && ! $request->acceptsHtml()) {
                return null;
            }

            if (
                $e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof HttpExceptionInterface
            ) {
                return null;
            }

            return Inertia::render('Errors/AppError', [
                'status' => 500,
                'message' => 'Something went wrong on our end.',
            ])->toResponse($request)->setStatusCode(500);
        });
    })->create();
