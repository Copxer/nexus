<?php

namespace Tests\Unit\Domain\Observability\Jobs;

use App\Domain\Observability\Jobs\CheckGitHubRateLimitJob;
use App\Models\GithubConnection;
use App\Models\GithubRateLimitSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckGitHubRateLimitJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_op_when_no_connections_exist(): void
    {
        Http::fake();

        (new CheckGitHubRateLimitJob)->handle();

        $this->assertSame(0, GithubRateLimitSnapshot::query()->count());
        Http::assertNothingSent();
    }

    public function test_persists_a_snapshot_per_connection(): void
    {
        $user = User::factory()->create();
        GithubConnection::query()->create([
            'user_id' => $user->id,
            'github_user_id' => '9001',
            'github_username' => 'octocat',
            'access_token' => 'gho_token',
            'expires_at' => now()->addHours(8),
            'connected_at' => now(),
        ]);

        Http::fake([
            'api.github.com/rate_limit' => Http::response([
                'resources' => [
                    'core' => [
                        'limit' => 5000,
                        'remaining' => 4321,
                        'reset' => 1700000000,
                    ],
                ],
            ]),
        ]);

        (new CheckGitHubRateLimitJob)->handle();

        $snapshot = GithubRateLimitSnapshot::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(4321, $snapshot->remaining);
        $this->assertSame(5000, $snapshot->limit);
        $this->assertSame(1700000000, $snapshot->reset_at->getTimestamp());
    }

    public function test_skips_connection_on_api_failure_without_crashing(): void
    {
        $user = User::factory()->create();
        GithubConnection::query()->create([
            'user_id' => $user->id,
            'github_user_id' => '9001',
            'github_username' => 'octocat',
            'access_token' => 'gho_token',
            'expires_at' => now()->addHours(8),
            'connected_at' => now(),
        ]);

        Http::fake([
            'api.github.com/rate_limit' => Http::response(['message' => 'Bad credentials'], 401),
        ]);

        // Should not throw.
        (new CheckGitHubRateLimitJob)->handle();

        $this->assertSame(0, GithubRateLimitSnapshot::query()->count());
    }

    public function test_skips_expired_connections(): void
    {
        $user = User::factory()->create();
        GithubConnection::query()->create([
            'user_id' => $user->id,
            'github_user_id' => '9001',
            'github_username' => 'octocat',
            'access_token' => '', // spec 037 expire flow blanks the token
            'expires_at' => now()->subHour(),
            'connected_at' => now()->subDay(),
        ]);

        Http::fake();

        (new CheckGitHubRateLimitJob)->handle();

        Http::assertNothingSent();
        $this->assertSame(0, GithubRateLimitSnapshot::query()->count());
    }
}
