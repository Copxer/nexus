<?php

namespace Tests\Feature\GitHub\Webhooks;

use App\Domain\Activity\Actions\CreateActivityEventAction;
use App\Domain\GitHub\WebhookHandlers\WorkflowRunWebhookHandler;
use App\Enums\WebhookDeliveryStatus;
use App\Events\ActivityEventCreated;
use App\Models\ActivityEvent;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WorkflowRunWebhookHandlerTest extends TestCase
{
    use RefreshDatabase;

    private function handler(): WorkflowRunWebhookHandler
    {
        return new WorkflowRunWebhookHandler(new CreateActivityEventAction);
    }

    private function deliveryFor(
        string $action,
        string $conclusion,
        string $fullName = 'octocat/hello-world',
    ): WebhookDelivery {
        return WebhookDelivery::factory()->create([
            'event' => 'workflow_run',
            'action' => $action,
            'repository_full_name' => $fullName,
            'payload_json' => [
                'action' => $action,
                'repository' => ['full_name' => $fullName],
                'workflow_run' => [
                    'id' => 1234,
                    'name' => 'CI',
                    'head_branch' => 'main',
                    'run_number' => 42,
                    'conclusion' => $conclusion,
                    'status' => 'completed',
                    'updated_at' => '2026-04-29T12:00:00Z',
                    'run_started_at' => '2026-04-29T11:55:00Z',
                    'html_url' => 'https://github.com/octocat/hello-world/actions/runs/1234',
                ],
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

    public function test_completed_success_creates_workflow_succeeded_activity(): void
    {
        Event::fake([ActivityEventCreated::class]);
        $this->importedRepository();
        $delivery = $this->deliveryFor('completed', 'success');

        $status = $this->handler()->handle($delivery);

        $this->assertSame(WebhookDeliveryStatus::Processed, $status);
        $this->assertDatabaseHas('activity_events', [
            'event_type' => 'workflow.succeeded',
            'severity' => 'success',
        ]);
        Event::assertDispatched(ActivityEventCreated::class);
    }

    public function test_completed_failure_creates_workflow_failed_activity(): void
    {
        Event::fake([ActivityEventCreated::class]);
        $this->importedRepository();
        $delivery = $this->deliveryFor('completed', 'failure');

        $status = $this->handler()->handle($delivery);

        $this->assertSame(WebhookDeliveryStatus::Processed, $status);
        $this->assertDatabaseHas('activity_events', [
            'event_type' => 'workflow.failed',
            'severity' => 'danger',
        ]);
    }

    public function test_in_progress_action_is_skipped(): void
    {
        $this->importedRepository();
        $delivery = $this->deliveryFor('in_progress', 'null');

        $status = $this->handler()->handle($delivery);

        $this->assertSame(WebhookDeliveryStatus::Skipped, $status);
        $this->assertSame(0, ActivityEvent::query()->count());
    }

    public function test_unhandled_conclusion_is_skipped(): void
    {
        $this->importedRepository();
        $delivery = $this->deliveryFor('completed', 'neutral');

        $status = $this->handler()->handle($delivery);

        $this->assertSame(WebhookDeliveryStatus::Skipped, $status);
        $this->assertSame(0, ActivityEvent::query()->count());
    }

    public function test_unimported_repository_is_skipped_not_failed(): void
    {
        $delivery = $this->deliveryFor('completed', 'success', 'octocat/uh-oh');

        $status = $this->handler()->handle($delivery);

        $this->assertSame(WebhookDeliveryStatus::Skipped, $status);
        $this->assertSame(0, ActivityEvent::query()->count());
    }
}
