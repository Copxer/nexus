<?php

namespace App\Domain\GitHub\Exceptions;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Single typed exception for any GitHub REST API failure. Callers switch
 * on status helpers rather than instanceof against a hierarchy of
 * subclasses; the spec calls for a single class with helpers.
 *
 * Two construction paths:
 *   - `GitHubApiException::fromResponse($response)` — semantic GitHub error
 *     payload (Response.json() has an `error`/`message` field) or any
 *     non-2xx status code that the caller wants to wrap.
 *   - `GitHubApiException::fromTransport($e, $message)` — wraps an
 *     `Illuminate\Http\Client\RequestException` (HTTP transport failure
 *     before GitHub answered).
 */
class GitHubApiException extends RuntimeException
{
    /** GitHub HTTP status code, or 0 for transport-layer failures. */
    public readonly int $statusCode;

    /**
     * Spec 037 — unix timestamp at which the rate-limit window resets.
     * Populated from the `X-RateLimit-Reset` header on 429 / rate-
     * limited 403 responses. Drives `secondsUntilReset()` so sync
     * jobs can `release()` themselves until the window clears
     * instead of consuming retries on a guaranteed failure.
     */
    public readonly ?int $rateLimitResetAt;

    public function __construct(
        string $message,
        int $statusCode = 0,
        ?Throwable $previous = null,
        ?int $rateLimitResetAt = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->statusCode = $statusCode;
        $this->rateLimitResetAt = $rateLimitResetAt;
    }

    public static function fromResponse(Response $response, string $context = 'GitHub request failed'): self
    {
        $status = $response->status();
        $body = $response->json();
        $reason = is_array($body)
            ? ($body['message'] ?? $body['error_description'] ?? $body['error'] ?? 'unknown')
            : 'invalid response';

        // Defensive: GitHub's `message` is a string in practice, but a
        // malformed payload (or future API change) could deliver an
        // array/object. Coerce non-strings to a stable label so we never
        // emit `Array` into a thrown message that bubbles into the UI.
        if (! is_string($reason)) {
            $reason = 'unknown';
        }

        // Spec 037 — parse the reset window out of GitHub's standard
        // headers. Present on 429 + rate-limited 403 responses; we
        // also accept the spelling variants because the client we
        // see in tests sometimes lower-cases headers. `null` when
        // the response isn't rate-limited.
        $resetAt = null;
        foreach (['X-RateLimit-Reset', 'x-ratelimit-reset'] as $name) {
            $header = $response->header($name);
            if ($header !== '' && $header !== null) {
                $resetAt = (int) $header;
                break;
            }
        }

        return new self("{$context}: HTTP {$status} {$reason}", $status, null, $resetAt);
    }

    public static function fromTransport(RequestException $e, string $context = 'GitHub request failed'): self
    {
        return new self(
            "{$context}: ".$e->getMessage(),
            $e->response?->status() ?? 0,
            $e,
        );
    }

    /** True for HTTP 401 — token revoked, expired, or never valid. */
    public function isUnauthorized(): bool
    {
        return $this->statusCode === 401;
    }

    /**
     * True when GitHub reported a rate-limit exhaustion. Two signals
     * count, in priority order:
     *
     *   1. The response carried `X-RateLimit-Reset` AND the status
     *      code is 429 or 403 — header-driven, the strong path.
     *   2. The status is 403 and the body mentions "rate limit" —
     *      heuristic fallback for cases where the header didn't
     *      reach us (proxies, malformed responses).
     */
    public function wasRateLimited(): bool
    {
        if (
            $this->rateLimitResetAt !== null
            && ($this->statusCode === 429 || $this->statusCode === 403)
        ) {
            return true;
        }

        return $this->statusCode === 403
            && str_contains(strtolower($this->getMessage()), 'rate limit');
    }

    /**
     * Spec 037 — seconds until the rate-limit window resets. Returns
     * `0` when there's no reset timestamp or the timestamp is already
     * in the past (eg. a stale exception serialized into a delayed
     * job). Callers use this for `$this->release($n)` so the next
     * attempt lands after GitHub agrees to talk again.
     */
    public function secondsUntilReset(): int
    {
        if ($this->rateLimitResetAt === null) {
            return 0;
        }

        // `Carbon::now()->timestamp` over PHP's `time()` so tests can
        // freeze the clock with `Carbon::setTestNow()` and assert the
        // delta deterministically.
        return max(0, $this->rateLimitResetAt - Carbon::now()->getTimestamp());
    }
}
