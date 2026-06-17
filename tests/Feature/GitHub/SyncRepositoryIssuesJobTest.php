<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\Actions\NormalizeGitHubIssueAction;
use App\Domain\GitHub\Actions\SyncRepositoryIssuesAction;
use App\Domain\GitHub\Exceptions\GitHubApiException;
use App\Domain\GitHub\Jobs\SyncRepositoryIssuesJob;
use App\Enums\RepositorySyncStatus;
use App\Models\GithubConnection;
use App\Models\GithubIssue;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncRepositoryIssuesJobTest extends TestCase
{
    use RefreshDatabase;

    private function setUpProjectWithConnection(): array
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
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'octocat/hello-world',
            'issues_sync_status' => RepositorySyncStatus::Pending->value,
        ]);

        return ['user' => $user, 'repository' => $repository];
    }

    private function action(): SyncRepositoryIssuesAction
    {
        return new SyncRepositoryIssuesAction(new NormalizeGitHubIssueAction);
    }

    public function test_handle_syncs_issues_and_flips_status_to_synced(): void
    {
        $context = $this->setUpProjectWithConnection();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/issues*' => Http::response([
                [
                    'id' => 1,
                    'number' => 1,
                    'title' => 'First',
                    'state' => 'open',
                    'created_at' => '2026-04-01T00:00:00Z',
                    'updated_at' => '2026-04-15T00:00:00Z',
                ],
            ]),
        ]);

        (new SyncRepositoryIssuesJob($context['repository']->id))->handle($this->action());

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Synced, $repo->issues_sync_status);
        $this->assertNotNull($repo->issues_synced_at);
        $this->assertSame(1, GithubIssue::query()->count());
    }

    public function test_handle_marks_unauthorized_and_expires_connection_on_401(): void
    {
        // Spec 037 — 401 is terminal (token won't self-heal). The job
        // short-circuits the retry pipeline and marks `Unauthorized`
        // directly instead of consuming retries on guaranteed failure.
        $context = $this->setUpProjectWithConnection();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/issues*' => Http::response(
                ['message' => 'Bad credentials'],
                401,
            ),
        ]);

        (new SyncRepositoryIssuesJob($context['repository']->id))->handle($this->action());

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Unauthorized, $repo->issues_sync_status);

        $connection = $context['user']->fresh()->githubConnection;
        $this->assertSame('', $connection->access_token);
        $this->assertFalse($connection->isAccessTokenValid());
    }

    public function test_handle_throws_transient_errors_so_the_retry_pipeline_runs(): void
    {
        // Spec 037 — 5xx and other transient API failures are no longer
        // caught + persisted as `Failed` in-job. They throw so the
        // queue worker re-queues per `$backoff()` and `failed()`
        // persists the terminal status after `$tries` is exhausted.
        $context = $this->setUpProjectWithConnection();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/issues*' => Http::response(
                ['message' => 'Boom'],
                500,
            ),
        ]);

        $this->expectException(GitHubApiException::class);

        try {
            (new SyncRepositoryIssuesJob($context['repository']->id))->handle($this->action());
        } finally {
            // Status stayed `Syncing` — the row is in-flight, not failed,
            // until the retry pipeline exhausts.
            $repo = $context['repository']->fresh();
            $this->assertSame(RepositorySyncStatus::Syncing, $repo->issues_sync_status);

            // 500 is not unauthorized — connection stays valid.
            $connection = $context['user']->fresh()->githubConnection;
            $this->assertNotSame('', $connection->access_token);
        }
    }

    public function test_failed_handler_persists_terminal_failed_status_after_retries_exhausted(): void
    {
        // Spec 037 — Laravel calls `failed()` after `$tries` is gone.
        // It stamps `Failed` + the message + `*_sync_failed_at` so the
        // UI surfaces the terminal state.
        $context = $this->setUpProjectWithConnection();

        $job = new SyncRepositoryIssuesJob($context['repository']->id);
        $exception = new GitHubApiException('Boom', 500);

        $job->failed($exception);

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Failed, $repo->issues_sync_status);
        $this->assertNotNull($repo->issues_sync_error);
        $this->assertStringContainsString('Boom', $repo->issues_sync_error);
        $this->assertNotNull($repo->issues_sync_failed_at);
    }

    public function test_failed_handler_maps_401_through_to_unauthorized(): void
    {
        // Defensive — if a 401 survives the in-job catch (eg. wrapped
        // in a Throwable), `failed()` still maps it correctly.
        $context = $this->setUpProjectWithConnection();

        $job = new SyncRepositoryIssuesJob($context['repository']->id);
        $exception = new GitHubApiException('Bad credentials', 401);

        $job->failed($exception);

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Unauthorized, $repo->issues_sync_status);
    }

    public function test_handle_releases_for_rate_limit_until_reset_window(): void
    {
        // Spec 037 — 429 (or rate-limited 403) releases back to the
        // queue with a delay matched to GitHub's `X-RateLimit-Reset`.
        // `release()` does NOT consume a retry attempt.
        Carbon::setTestNow(Carbon::createFromTimestamp(1000));

        $context = $this->setUpProjectWithConnection();
        $resetAt = 1300; // 5 minutes in the future

        Http::fake([
            'api.github.com/repos/octocat/hello-world/issues*' => Http::response(
                ['message' => 'API rate limit exceeded'],
                429,
                ['X-RateLimit-Reset' => (string) $resetAt],
            ),
        ]);

        $job = new SyncRepositoryIssuesJob($context['repository']->id);
        $job->handle($this->action());

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::RateLimited, $repo->issues_sync_status);
        $this->assertNotNull($repo->issues_sync_error);
        // No failed_at stamp — rate-limit isn't a failure.
        $this->assertNull($repo->issues_sync_failed_at);

        Carbon::setTestNow();
    }

    public function test_handle_preserves_issues_synced_at_when_thrown_for_retry(): void
    {
        // Spec 037 — `issues_synced_at` stays at the previous successful
        // value while the retry pipeline runs (status is `Syncing`).
        // After all retries fail, `failed()` doesn't touch it either —
        // tested separately above.
        $context = $this->setUpProjectWithConnection();
        $priorSync = now()->subHours(3);
        $context['repository']->forceFill(['issues_synced_at' => $priorSync])->save();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/issues*' => Http::response(
                ['message' => 'Boom'],
                500,
            ),
        ]);

        try {
            (new SyncRepositoryIssuesJob($context['repository']->id))->handle($this->action());
        } catch (GitHubApiException) {
            // expected
        }

        $repo = $context['repository']->fresh();
        $this->assertEquals(
            $priorSync->toIso8601String(),
            $repo->issues_synced_at->toIso8601String(),
        );
    }

    public function test_handle_marks_failed_when_owner_has_no_connection(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'issues_sync_status' => RepositorySyncStatus::Pending->value,
        ]);

        (new SyncRepositoryIssuesJob($repository->id))->handle($this->action());

        $this->assertSame(RepositorySyncStatus::Failed, $repository->fresh()->issues_sync_status);
    }

    public function test_handle_is_a_no_op_when_repository_is_missing(): void
    {
        (new SyncRepositoryIssuesJob(999_999))->handle($this->action());

        $this->assertSame(0, Repository::query()->count());
        $this->assertSame(0, GithubIssue::query()->count());
    }

    public function test_handle_throws_404_so_the_retry_pipeline_runs(): void
    {
        // Spec 037 — 404 is treated as transient (some endpoints flake
        // on 404 before settling). `failed()` is what persists `Failed`
        // after the retry budget is gone; coverage is in
        // `test_failed_handler_persists_terminal_failed_status_after_retries_exhausted`.
        $context = $this->setUpProjectWithConnection();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/issues*' => Http::response(
                ['message' => 'Not Found'],
                404,
            ),
        ]);

        $this->expectException(GitHubApiException::class);

        (new SyncRepositoryIssuesJob($context['repository']->id))->handle($this->action());
    }

    public function test_handle_clears_issues_sync_error_after_successful_resync(): void
    {
        $context = $this->setUpProjectWithConnection();

        $context['repository']->forceFill([
            'issues_sync_status' => RepositorySyncStatus::Failed->value,
            'issues_sync_error' => 'Stale failure message',
            'issues_sync_failed_at' => now()->subMinutes(10),
        ])->save();

        Http::fake([
            'api.github.com/repos/octocat/hello-world/issues*' => Http::response([]),
        ]);

        (new SyncRepositoryIssuesJob($context['repository']->id))->handle($this->action());

        $repo = $context['repository']->fresh();
        $this->assertSame(RepositorySyncStatus::Synced, $repo->issues_sync_status);
        $this->assertNull($repo->issues_sync_error);
        $this->assertNull($repo->issues_sync_failed_at);
    }
}
