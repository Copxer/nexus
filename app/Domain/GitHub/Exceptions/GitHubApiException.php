<?php

namespace App\Domain\GitHub\Exceptions;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
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

    public function __construct(
        string $message,
        int $statusCode = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->statusCode = $statusCode;
    }

    public static function fromResponse(Response $response, string $context = 'GitHub request failed'): self
    {
        $status = $response->status();
        $body = $response->json();
        $reason = is_array($body)
            ? ($body['message'] ?? $body['error_description'] ?? $body['error'] ?? 'unknown')
            : 'invalid response';

        return new self("{$context}: HTTP {$status} {$reason}", $status);
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
     * True for HTTP 403 with a rate-limit-exhaustion shape (GitHub's
     * "API rate limit exceeded" path). Best-effort heuristic — caller
     * should still log + surface to the user.
     */
    public function wasRateLimited(): bool
    {
        return $this->statusCode === 403
            && str_contains(strtolower($this->getMessage()), 'rate limit');
    }
}
