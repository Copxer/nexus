<?php

namespace Tests\Unit\Domain\GitHub\Exceptions;

use App\Domain\GitHub\Exceptions\GitHubApiException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GitHubApiExceptionTest extends TestCase
{
    public function test_was_rate_limited_returns_true_when_reset_header_is_set_on_429(): void
    {
        $exception = new GitHubApiException('rate limited', 429, null, time() + 60);

        $this->assertTrue($exception->wasRateLimited());
    }

    public function test_was_rate_limited_returns_true_when_reset_header_is_set_on_rate_limited_403(): void
    {
        $exception = new GitHubApiException('forbidden', 403, null, time() + 60);

        $this->assertTrue($exception->wasRateLimited());
    }

    public function test_was_rate_limited_falls_back_to_heuristic_when_no_reset_header(): void
    {
        // No header → still detected via the message-pattern fallback
        // when GitHub reports a 403 with "rate limit" in the body.
        $exception = new GitHubApiException('GitHub: API rate limit exceeded', 403);

        $this->assertTrue($exception->wasRateLimited());
    }

    public function test_was_rate_limited_returns_false_for_unrelated_errors(): void
    {
        $exception = new GitHubApiException('Not Found', 404);

        $this->assertFalse($exception->wasRateLimited());
    }

    public function test_is_unauthorized_returns_true_for_401(): void
    {
        $exception = new GitHubApiException('Unauthorized', 401);

        $this->assertTrue($exception->isUnauthorized());
        $this->assertFalse($exception->wasRateLimited());
    }

    public function test_seconds_until_reset_returns_positive_delta_for_future_reset(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1000));
        $exception = new GitHubApiException('rate limited', 429, null, 1060);

        $this->assertSame(60, $exception->secondsUntilReset());

        Carbon::setTestNow();
    }

    public function test_seconds_until_reset_returns_zero_for_past_or_missing_reset(): void
    {
        // No reset header at all → 0.
        $noReset = new GitHubApiException('Internal Server Error', 500);
        $this->assertSame(0, $noReset->secondsUntilReset());

        // Reset in the past (eg. stale exception on a delayed job) → 0.
        Carbon::setTestNow(Carbon::createFromTimestamp(2000));
        $staleReset = new GitHubApiException('rate limited', 429, null, 1000);
        $this->assertSame(0, $staleReset->secondsUntilReset());
        Carbon::setTestNow();
    }

    public function test_from_response_extracts_x_rate_limit_reset_header(): void
    {
        $resetAt = time() + 120;
        $psr = new \GuzzleHttp\Psr7\Response(429, [
            'X-RateLimit-Reset' => (string) $resetAt,
        ], json_encode(['message' => 'API rate limit exceeded']));

        $response = new Response($psr);

        $exception = GitHubApiException::fromResponse($response, 'test');

        $this->assertSame(429, $exception->statusCode);
        $this->assertSame($resetAt, $exception->rateLimitResetAt);
        $this->assertTrue($exception->wasRateLimited());
        $this->assertSame(120, $exception->secondsUntilReset());
    }

    public function test_from_response_handles_responses_without_reset_header(): void
    {
        $psr = new \GuzzleHttp\Psr7\Response(404, [], json_encode(['message' => 'Not Found']));
        $response = new Response($psr);

        $exception = GitHubApiException::fromResponse($response, 'test');

        $this->assertSame(404, $exception->statusCode);
        $this->assertNull($exception->rateLimitResetAt);
        $this->assertSame(0, $exception->secondsUntilReset());
    }
}
