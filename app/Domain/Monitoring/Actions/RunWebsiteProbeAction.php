<?php

namespace App\Domain\Monitoring\Actions;

use App\Domain\Monitoring\Probes\WebsiteProbeResult;
use App\Enums\WebsiteCheckStatus;
use App\Models\Website;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pure HTTP probe — no DB writes. Returns a `WebsiteProbeResult` the
 * caller (controller / spec 024's job) hands to
 * `RecordWebsiteCheckAction` for persistence.
 *
 * Status mapping:
 *   - HTTP request succeeded with `expected_status_code`        → Up
 *     - if `response_time_ms > SLOW_THRESHOLD_MS`               → Slow (overrides Up)
 *   - HTTP request succeeded but status code mismatch           → Down
 *   - Transport error (DNS / timeout / refused / TLS / etc.)    → Error
 *
 * All HTTP traffic flows through Laravel's `Http` facade so tests
 * `Http::fake()` cleanly. Catch list is intentionally narrow —
 * `ConnectionException` covers DNS / TCP / TLS / timeout, and
 * `RequestException` covers HTTP-layer protocol failures Guzzle
 * throws despite our not calling `->throw()`. Programmer bugs (typo,
 * future enum drift, OOM) bubble up loudly instead of getting
 * silently classified as "site down."
 */
class RunWebsiteProbeAction
{
    /**
     * Phase-1 hard threshold for the `Slow` classification. Past
     * 3 seconds a successful probe still marks the site Slow.
     * Per-website configuration is a future polish if real users
     * complain.
     */
    private const SLOW_THRESHOLD_MS = 3_000;

    /** Hard cap on the persisted `error_message` length. */
    private const ERROR_MESSAGE_LIMIT = 500;

    public function execute(Website $website): WebsiteProbeResult
    {
        $startedAt = hrtime(true);

        try {
            $response = Http::timeout($website->timeout_ms / 1_000)
                ->withHeaders(['User-Agent' => 'Nexus-Monitor'])
                ->{$this->httpMethod($website->method)}($website->url);
        } catch (ConnectionException|RequestException $e) {
            return $this->errorResult($e);
        }

        $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        $statusCode = $response->status();

        $status = $this->classify($website->expected_status_code, $statusCode, $elapsedMs);

        return new WebsiteProbeResult(
            status: $status,
            httpStatusCode: $statusCode,
            responseTimeMs: $elapsedMs,
            errorMessage: $this->errorMessageFor($status, $response),
        );
    }

    /**
     * Map probe outcome to a `WebsiteCheckStatus`. Slow takes
     * precedence over Up because a 200 OK at 5s is more interesting
     * than the 200 alone.
     */
    private function classify(int $expected, int $actual, int $elapsedMs): WebsiteCheckStatus
    {
        if ($actual !== $expected) {
            return WebsiteCheckStatus::Down;
        }

        if ($elapsedMs > self::SLOW_THRESHOLD_MS) {
            return WebsiteCheckStatus::Slow;
        }

        return WebsiteCheckStatus::Up;
    }

    /**
     * Allowed HTTP methods reduce to the lowercase `Http` macro
     * (`get`, `head`, `post`). Unknown / disallowed methods fall
     * back to `get` — the controller already validates the input.
     */
    private function httpMethod(string $method): string
    {
        return match (strtoupper($method)) {
            'HEAD' => 'head',
            'POST' => 'post',
            default => 'get',
        };
    }

    private function errorResult(Throwable $e): WebsiteProbeResult
    {
        $message = $e->getMessage() !== '' ? $e->getMessage() : $e::class;

        return new WebsiteProbeResult(
            status: WebsiteCheckStatus::Error,
            httpStatusCode: null,
            responseTimeMs: null,
            errorMessage: Str::limit($message, self::ERROR_MESSAGE_LIMIT, '…'),
        );
    }

    /**
     * `error_message` is set on Down/Error rows for surfacing in the
     * UI; Up/Slow leaves it null. Down captures the response body's
     * first line so an HTTP-level "Service Unavailable" surfaces in
     * the recent-checks list without needing a separate column.
     */
    private function errorMessageFor(WebsiteCheckStatus $status, Response $response): ?string
    {
        if ($status === WebsiteCheckStatus::Up || $status === WebsiteCheckStatus::Slow) {
            return null;
        }

        $body = trim((string) $response->body());

        if ($body === '') {
            return "HTTP {$response->status()}";
        }

        // Snip to first line for a stable preview, then cap to 500.
        $firstLine = strtok($body, "\n") ?: $body;

        return Str::limit("HTTP {$response->status()}: {$firstLine}", self::ERROR_MESSAGE_LIMIT, '…');
    }
}
