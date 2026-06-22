<?php

namespace App\Http\Middleware;

use App\Domain\Activity\Actions\CreateActivityEventAction;
use App\Enums\ActivitySeverity;
use App\Models\AgentToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token auth + per-token rate limiting for the agent telemetry
 * endpoint (spec 027).
 *
 *   1. Extract `Authorization: Bearer <plaintext>` header.
 *   2. Hash with `AgentToken::hash()` and look up an active token.
 *   3. Reject if the host has been archived (spec 026's archive flow
 *      revokes tokens, but a race or a manual DB edit could leave a
 *      live token attached to an archived host — defence in depth).
 *   4. Enforce per-token rate limit (60 req/min). Returns 429 with
 *      Retry-After when exceeded.
 *   5. Stamp `last_used_at` and stash both `agent_host` + `agent_token`
 *      on the request attributes so the controller can read them.
 *
 * Rate limiting lives here (rather than as a separate `throttle:`
 * middleware) because Laravel's default middleware priority puts
 * ThrottleRequests *before* unlisted custom middleware, so a named
 * limiter keyed on `$request->attributes->get('agent_token')` would
 * always see null. Doing both jobs in one middleware sidesteps that.
 *
 * No `Auth::login()` is performed — this is a machine-to-machine path
 * with the host as the actor, not a user. 401 on every auth failure
 * mode; empty body so an attacker can't probe valid token shapes.
 */
class AuthenticateAgent
{
    /** @var int Per-token requests per minute. */
    public const RATE_LIMIT_PER_MINUTE = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! is_string($bearer) || $bearer === '') {
            $this->recordAuthFailure($request, 'missing_bearer_token');

            return response('', 401);
        }

        $token = AgentToken::query()
            ->where('hashed_token', AgentToken::hash($bearer))
            ->whereNull('revoked_at')
            ->with('host')
            ->first();

        if ($token === null
            || $token->host === null
            || $token->host->archived_at !== null
        ) {
            $this->recordAuthFailure($request, 'invalid_token');

            return response('', 401);
        }

        $key = self::rateLimitKey($token);
        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT_PER_MINUTE)) {
            $this->recordAuthFailure($request, 'rate_limited');

            return response('', 429)->header(
                'Retry-After',
                (string) RateLimiter::availableIn($key),
            );
        }
        RateLimiter::hit($key, 60);

        // Stamped after the rate-limit check so a flood of throttled
        // requests doesn't keep the token's last_used_at fresh.
        $token->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('agent_host', $token->host);
        $request->attributes->set('agent_token', $token);

        return $next($request);
    }

    /**
     * Cache key for the per-token bucket. Public so tests can target
     * the same key without re-deriving it.
     */
    public static function rateLimitKey(AgentToken $token): string
    {
        return 'agent-telemetry:'.$token->getKey();
    }

    /**
     * Spec 038 — capture every rejected agent request as an activity
     * event so `EvaluateSystemHealthJob` can count "auth failures in
     * the last 5 min" without a dedicated table. IP + reason metadata
     * give an operator enough to triage (token leaked? wrong host
     * shipping the binary? rate-limit thrash?).
     *
     * Resolved out of the container so the existing constructor stays
     * dependency-free.
     */
    private function recordAuthFailure(Request $request, string $reason): void
    {
        app(CreateActivityEventAction::class)->execute([
            'event_type' => 'agent.auth.failure',
            'severity' => ActivitySeverity::Warning,
            'source' => 'agent',
            'title' => 'Agent token rejected',
            'occurred_at' => now(),
            'metadata' => [
                'ip' => $request->ip(),
                'reason' => $reason,
            ],
        ]);
    }
}
