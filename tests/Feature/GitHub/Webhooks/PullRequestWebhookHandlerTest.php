<?php

namespace Tests\Feature\GitHub\Webhooks;

use App\Domain\Activity\Actions\CreateActivityEventAction;
use App\Domain\GitHub\Actions\NormalizeGitHubPullRequestAction;
use App\Domain\GitHub\WebhookHandlers\PullRequestWebhookHandler;
use App\Enums\WebhookDeliveryStatus;
use App\Models\GithubPullRequest;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PullRequestWebhookHandlerTest extends TestCase
{
    use RefreshDatabase;

    private function handler(): PullRequestWebhookHandler
    {
        return new PullRequestWebhookHandler(
            new NormalizeGitHubPullRequestAction,
            new CreateActivityEventAction,
        );
    }

    private function importedRepository(string $fullName = 'octocat/hello-world'): Repository
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        return Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => $fullName,
        ]);
    }

    private function deliveryFor(string $action, array $prOverrides = [], string $fullName = 'octocat/hello-world'): WebhookDelivery
    {
        return WebhookDelivery::factory()->create([
            'event' => 'pull_request',
            'action' => $action,
            'repository_full_name' => $fullName,
            'payload_json' => [
                'action' => $action,
                'repository' => ['full_name' => $fullName],
                'pull_request' => array_merge([
                    'id' => 9999,
                    'number' => 12,
                    'title' => 'Add caching',
                    'state' => 'open',
                    'merged' => false,
                    'merged_at' => null,
                    'base' => ['ref' => 'main'],
                    'head' => ['ref' => 'topic/cache'],
                    'updated_at' => '2026-04-15T00:00:00Z',
                ], $prOverrides),
                'sender' => ['login' => 'bob'],
            ],
        ]);
    }

    public function test_opened_creates_pr_and_info_activity(): void
    {
        $repository = $this->importedRepository();
        $delivery = $this->deliveryFor('opened');

        $status = $this->handler()->handle($delivery);

        $this->assertSame(WebhookDeliveryStatus::Processed, $status);
        $this->assertSame(1, GithubPullRequest::query()->count());
        $this->assertDatabaseHas('activity_events', [
            'event_type' => 'pull_request.opened',
            'severity' => 'info',
            'repository_id' => $repository->id,
        ]);
    }

    public function test_closed_with_merged_creates_merged_activity(): void
    {
        $this->importedRepository();
        $delivery = $this->deliveryFor('closed', [
            'state' => 'closed',
            'merged' => true,
            'merged_at' => '2026-04-20T00:00:00Z',
        ]);

        $this->handler()->handle($delivery);

        $this->assertDatabaseHas('activity_events', [
            'event_type' => 'pull_request.merged',
            'severity' => 'success',
        ]);
        $this->assertDatabaseHas('github_pull_requests', [
            'state' => 'merged',
            'merged' => true,
        ]);
    }

    public function test_closed_without_merge_creates_closed_activity(): void
    {
        $this->importedRepository();
        $delivery = $this->deliveryFor('closed', [
            'state' => 'closed',
            'merged' => false,
            'closed_at' => '2026-04-20T00:00:00Z',
        ]);

        $this->handler()->handle($delivery);

        $this->assertDatabaseHas('activity_events', [
            'event_type' => 'pull_request.closed',
            'severity' => 'info',
        ]);
        $this->assertDatabaseHas('github_pull_requests', [
            'state' => 'closed',
            'merged' => false,
        ]);
    }

    public function test_reopened_and_review_requested_create_info_activities(): void
    {
        $this->importedRepository();

        $this->handler()->handle($this->deliveryFor('reopened'));
        $this->handler()->handle($this->deliveryFor('review_requested'));

        $this->assertDatabaseHas('activity_events', ['event_type' => 'pull_request.reopened']);
        $this->assertDatabaseHas('activity_events', ['event_type' => 'pull_request.review_requested']);
    }

    public function test_unknown_action_is_skipped(): void
    {
        $this->importedRepository();
        $delivery = $this->deliveryFor('synchronize');

        $status = $this->handler()->handle($delivery);

        $this->assertSame(WebhookDeliveryStatus::Skipped, $status);
        $this->assertNotNull($delivery->fresh()->error_message);
    }

    public function test_unimported_repository_is_skipped(): void
    {
        $delivery = $this->deliveryFor('opened', [], 'someone/notimported');

        $status = $this->handler()->handle($delivery);

        $this->assertSame(WebhookDeliveryStatus::Skipped, $status);
        $this->assertSame(
            'Repository not imported into Nexus.',
            $delivery->fresh()->error_message,
        );
    }
}
