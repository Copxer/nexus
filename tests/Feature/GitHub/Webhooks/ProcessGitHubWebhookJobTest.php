<?php

namespace Tests\Feature\GitHub\Webhooks;

use App\Domain\GitHub\Jobs\ProcessGitHubWebhookJob;
use App\Domain\GitHub\WebhookHandlers\IssuesWebhookHandler;
use App\Enums\WebhookDeliveryStatus;
use App\Models\ActivityEvent;
use App\Models\GithubIssue;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ProcessGitHubWebhookJobTest extends TestCase
{
    use RefreshDatabase;

    private function repository(string $fullName = 'octocat/hello-world'): Repository
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        return Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => $fullName,
        ]);
    }

    public function test_handles_issues_opened_payload(): void
    {
        $repository = $this->repository();

        $delivery = WebhookDelivery::factory()->create([
            'event' => 'issues',
            'action' => 'opened',
            'repository_full_name' => $repository->full_name,
            'status' => WebhookDeliveryStatus::Received->value,
            'payload_json' => [
                'action' => 'opened',
                'repository' => ['full_name' => $repository->full_name],
                'issue' => [
                    'id' => 1234,
                    'number' => 7,
                    'title' => 'Login fails',
                    'state' => 'open',
                    'user' => ['login' => 'alice'],
                    'created_at' => '2026-04-01T00:00:00Z',
                    'updated_at' => '2026-04-15T00:00:00Z',
                ],
                'sender' => ['login' => 'alice'],
            ],
        ]);

        (new ProcessGitHubWebhookJob($delivery->id))->handle();

        $this->assertSame(
            WebhookDeliveryStatus::Processed,
            $delivery->fresh()->status,
        );
        $this->assertSame(1, GithubIssue::query()->count());
        $this->assertDatabaseHas('activity_events', [
            'event_type' => 'issue.created',
            'severity' => 'info',
            'actor_login' => 'alice',
            'repository_id' => $repository->id,
        ]);
    }

    public function test_handles_pull_request_merged_payload(): void
    {
        $repository = $this->repository();

        $delivery = WebhookDelivery::factory()->create([
            'event' => 'pull_request',
            'action' => 'closed',
            'repository_full_name' => $repository->full_name,
            'status' => WebhookDeliveryStatus::Received->value,
            'payload_json' => [
                'action' => 'closed',
                'repository' => ['full_name' => $repository->full_name],
                'pull_request' => [
                    'id' => 9999,
                    'number' => 12,
                    'title' => 'Add caching',
                    'state' => 'closed',
                    'merged' => true,
                    'merged_at' => '2026-04-20T00:00:00Z',
                    'base' => ['ref' => 'main'],
                    'head' => ['ref' => 'topic/cache'],
                ],
                'sender' => ['login' => 'bob'],
            ],
        ]);

        (new ProcessGitHubWebhookJob($delivery->id))->handle();

        $this->assertSame(WebhookDeliveryStatus::Processed, $delivery->fresh()->status);
        $this->assertDatabaseHas('github_pull_requests', [
            'github_id' => 9999,
            'state' => 'merged',
        ]);
        $this->assertDatabaseHas('activity_events', [
            'event_type' => 'pull_request.merged',
            'severity' => 'success',
            'actor_login' => 'bob',
        ]);
    }

    public function test_unhandled_event_is_skipped_and_does_not_create_activity(): void
    {
        Log::spy();
        $delivery = WebhookDelivery::factory()->create([
            'event' => 'milestone',
            'action' => 'created',
            'status' => WebhookDeliveryStatus::Received->value,
            'payload_json' => ['action' => 'created'],
        ]);

        (new ProcessGitHubWebhookJob($delivery->id))->handle();

        $this->assertSame(WebhookDeliveryStatus::Skipped, $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->processed_at);
        $this->assertSame(0, ActivityEvent::query()->count());
        Log::shouldHaveReceived('info')->atLeast()->once();
    }

    public function test_handler_exception_flips_to_failed_with_error_message(): void
    {
        // Force an exception path: an `issues` event with a non-array
        // `issue` block trips the normalizer and the handler returns
        // Skipped — that's the polite path. Here we want the
        // exception-caught path. Easiest reproduction: omit the
        // `payload_json` array on the row so $delivery->payload_json
        // is null and the handler's array spread blows up.
        $delivery = WebhookDelivery::factory()->create([
            'event' => 'issues',
            'action' => 'opened',
            'repository_full_name' => 'whoever/whatever',
            'status' => WebhookDeliveryStatus::Received->value,
            // Force the JSON cast to be empty array, then we'll mutate
            // raw to break it. Eloquent's `array` cast tolerates null;
            // simpler: rely on missing repository to take the Skipped
            // branch and assert that path instead. We test the actual
            // exception-path via the job's `try { … } catch` by
            // breaking the handler resolution itself — we'll override
            // the container binding.
        ]);

        // Re-bind the handler to throw on `handle`.
        $this->app->bind(
            IssuesWebhookHandler::class,
            fn () => new class
            {
                public function handle($delivery)
                {
                    throw new \RuntimeException(
                        "Synthetic boom on delivery #{$delivery->id}",
                    );
                }
            },
        );

        (new ProcessGitHubWebhookJob($delivery->id))->handle();

        $fresh = $delivery->fresh();
        $this->assertSame(WebhookDeliveryStatus::Failed, $fresh->status);
        $this->assertStringStartsWith('Synthetic boom', $fresh->error_message);
        $this->assertNotNull($fresh->processed_at);
    }

    public function test_handle_is_a_no_op_when_delivery_is_missing(): void
    {
        (new ProcessGitHubWebhookJob(999_999))->handle();

        $this->assertSame(0, WebhookDelivery::query()->count());
        $this->assertSame(0, ActivityEvent::query()->count());
    }
}
