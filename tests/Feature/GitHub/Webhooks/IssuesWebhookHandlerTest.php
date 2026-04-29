<?php

namespace Tests\Feature\GitHub\Webhooks;

use App\Domain\Activity\Actions\CreateActivityEventAction;
use App\Domain\GitHub\Actions\NormalizeGitHubIssueAction;
use App\Domain\GitHub\WebhookHandlers\IssuesWebhookHandler;
use App\Enums\WebhookDeliveryStatus;
use App\Models\ActivityEvent;
use App\Models\GithubIssue;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssuesWebhookHandlerTest extends TestCase
{
    use RefreshDatabase;

    private function handler(): IssuesWebhookHandler
    {
        return new IssuesWebhookHandler(
            new NormalizeGitHubIssueAction,
            new CreateActivityEventAction,
        );
    }

    private function deliveryFor(string $action, array $issueOverrides = [], string $fullName = 'octocat/hello-world'): WebhookDelivery
    {
        return WebhookDelivery::factory()->create([
            'event' => 'issues',
            'action' => $action,
            'repository_full_name' => $fullName,
            'payload_json' => [
                'action' => $action,
                'repository' => ['full_name' => $fullName],
                'issue' => array_merge([
                    'id' => 7,
                    'number' => 42,
                    'title' => 'Test issue',
                    'state' => 'open',
                    'user' => ['login' => 'alice'],
                    'updated_at' => '2026-04-15T12:00:00Z',
                    'closed_at' => null,
                ], $issueOverrides),
                'sender' => ['login' => 'alice'],
            ],
        ]);
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

    public function test_opened_creates_issue_and_info_activity(): void
    {
        $repository = $this->importedRepository();
        $delivery = $this->deliveryFor('opened');

        $status = $this->handler()->handle($delivery);

        $this->assertSame(WebhookDeliveryStatus::Processed, $status);
        $this->assertSame(1, GithubIssue::query()->count());
        $this->assertDatabaseHas('activity_events', [
            'event_type' => 'issue.created',
            'severity' => 'info',
            'repository_id' => $repository->id,
        ]);
    }

    public function test_closed_creates_success_activity(): void
    {
        $this->importedRepository();
        $delivery = $this->deliveryFor('closed', [
            'state' => 'closed',
            'closed_at' => '2026-04-20T00:00:00Z',
        ]);

        $this->handler()->handle($delivery);

        $this->assertDatabaseHas('activity_events', [
            'event_type' => 'issue.closed',
            'severity' => 'success',
        ]);
    }

    public function test_reopened_and_edited_create_info_activities(): void
    {
        $this->importedRepository();

        $this->handler()->handle($this->deliveryFor('reopened'));
        $this->handler()->handle($this->deliveryFor('edited'));

        $this->assertDatabaseHas('activity_events', ['event_type' => 'issue.reopened']);
        $this->assertDatabaseHas('activity_events', ['event_type' => 'issue.updated']);
    }

    public function test_unknown_action_is_skipped(): void
    {
        $this->importedRepository();
        $delivery = $this->deliveryFor('locked');

        $status = $this->handler()->handle($delivery);

        $this->assertSame(WebhookDeliveryStatus::Skipped, $status);
        $this->assertSame(0, ActivityEvent::query()->count());
        $this->assertNotNull($delivery->fresh()->error_message);
    }

    public function test_pull_request_shaped_payload_is_skipped(): void
    {
        $this->importedRepository();
        $delivery = $this->deliveryFor('opened', [
            'pull_request' => ['url' => 'https://api.github.com/...'],
        ]);

        $status = $this->handler()->handle($delivery);

        $this->assertSame(WebhookDeliveryStatus::Skipped, $status);
        $this->assertSame(0, ActivityEvent::query()->count());
    }

    public function test_unimported_repository_is_skipped(): void
    {
        // No Repository row exists for this full_name.
        $delivery = $this->deliveryFor('opened', [], 'someone/notimported');

        $status = $this->handler()->handle($delivery);

        $this->assertSame(WebhookDeliveryStatus::Skipped, $status);
        $this->assertSame(0, ActivityEvent::query()->count());
        $this->assertSame(
            'Repository not imported into Nexus.',
            $delivery->fresh()->error_message,
        );
    }
}
