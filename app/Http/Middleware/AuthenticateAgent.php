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

        // Spec 039 — opt-in fingerprint binding. Token issuance can
        // request a per-request fingerprint check (sha256 of IP +
        // User-Agent). First successful request binds; subsequent
        // requests must match. Skipped when the opt-in is off, which
        // is the default for backward compatibility.
        if ($token->fingerprint_enabled) {
            $expected = self::fingerprint($request);

            if ($token->fingerprint_hash === null) {
                $token->forceFill(['fingerprint_hash' => $expected])->save();
            } elseif (! hash_equals($token->fingerprint_hash, $expected)) {
                $this->recordAuthFailure($request, 'fingerprint_mismatch');

                return response('', 401);
            }
        }

        // Stamped after the rate-limit + fingerprint checks so a flood
        // of throttled or mismatched requests doesn't keep the token's
        // last_used_at fresh.
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
     * Spec 039 — per-request fingerprint hash. Combines the client
     * IP and User-Agent string; not a strong identifier on its own
     * (a determined attacker who already has the token can spoof
     * both), but raises the bar against casual token leakage.
     *
     * Public + static so tests can pin the hash deterministically.
     */
    public static function fingerprint(Request $request): string
    {
        return hash(
            'sha256',
            ($request->ip() ?? '').'|'.($request->userAgent() ?? ''),
        );
    }

    /**
     * Spec 038 — capture every rejected agent request as an activity
     * event so `EvaluateSystemHealthJob` can count "auth failures in
     * the last 5 min" without a dedicated table. IP + reason metadata
     * give an operator enough to triage (token leaked? wrong host
     * shipping the binary? rate-limit thrash?).
     *
     * Deduped per-IP-per-reason-per-minute via `RateLimiter::attempt`
     * so a misbehaving agent in a tight retry loop doesn't pump the
     * `activity_events` table at request rate. The 5-min count
     * threshold (10 events → warning) still trips cleanly: one
     * offender for 10 minutes, or 10 distinct IPs at any rate.
     *
     * Severity is `Info` — a single rejection isn't alarming; the
     * evaluator decides when the *count* across IPs is.
     *
     * Resolved out of the container so the existing constructor
     * stays dependency-free.
     */
    private function recordAuthFailure(Request $request, string $reason): void
    {
        RateLimiter::attempt(
            'agent-auth-failure-event:'.$request->ip().':'.$reason,
            maxAttempts: 1,
            callback: fn () => app(CreateActivityEventAction::class)->execute([
                'event_type' => 'agent.auth.failure',
                'severity' => ActivitySeverity::Info,
                'source' => 'agent',
                'title' => 'Agent token rejected',
                'occurred_at' => now(),
                'metadata' => [
                    'ip' => $request->ip(),
                    'reason' => $reason,
                ],
            ]),
            decaySeconds: 60,
        );
    }
}
