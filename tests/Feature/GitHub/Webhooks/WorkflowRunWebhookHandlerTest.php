<?php

namespace Tests\Feature\GitHub\Webhooks;

use App\Domain\Activity\Actions\CreateActivityEventAction;
use App\Domain\GitHub\Actions\NormalizeGitHubWorkflowRunAction;
use App\Domain\GitHub\WebhookHandlers\WorkflowRunWebhookHandler;
use App\Enums\WebhookDeliveryStatus;
use App\Events\ActivityEventCreated;
use App\Events\WorkflowRunUpserted;
use App\Models\ActivityEvent;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WorkflowRunWebhookHandlerTest extends TestCase
{
    use RefreshDatabase;

    private function handler(): WorkflowRunWebhookHandler
    {
        return new WorkflowRunWebhookHandler(
            new CreateActivityEventAction,
            new NormalizeGitHubWorkflowRunAction,
        );
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
                    'head_sha' => 'a'.str_repeat('1', 39),
                    'event' => 'push',
                    'run_number' => 42,
                    'conclusion' => $conclusion,
                    'status' => 'completed',
                    'updated_at' => '2026-04-29T12:00:00Z',
                    'run_started_at' => '2026-04-29T11:55:00Z',
                    'html_url' => 'https://github.com/octocat/hello-world/actions/runs/1234',
                    'actor' => ['login' => 'alice'],
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
        // No FK target for the upsert when the repo isn't imported yet.
        $this->assertSame(0, WorkflowRun::query()->count());
    }

    public function test_completed_handled_run_upserts_into_workflow_runs(): void
    {
        Event::fake([ActivityEventCreated::class]);
        $repository = $this->importedRepository();
        $delivery = $this->deliveryFor('completed', 'success');

        $this->handler()->handle($delivery);

        $this->assertSame(1, WorkflowRun::query()->count());
        $run = WorkflowRun::query()->first();
        $this->assertSame($repository->id, $run->repository_id);
        $this->assertSame(1234, $run->github_id);
        $this->assertSame(42, $run->run_number);
        $this->assertSame('CI', $run->name);
        $this->assertSame('completed', $run->status->value);
        $this->assertSame('success', $run->conclusion->value);
        $this->assertSame('main', $run->head_branch);
        $this->assertSame('alice', $run->actor_login);
    }

    public function test_in_progress_action_still_upserts_workflow_run(): void
    {
        $this->importedRepository();
        $delivery = WebhookDelivery::factory()->create([
            'event' => 'workflow_run',
            'action' => 'in_progress',
            'repository_full_name' => 'octocat/hello-world',
            'payload_json' => [
                'action' => 'in_progress',
                'repository' => ['full_name' => 'octocat/hello-world'],
                'workflow_run' => [
                    'id' => 5678,
                    'name' => 'Deploy',
                    'head_branch' => 'main',
                    'head_sha' => 'b'.str_repeat('2', 39),
                    'event' => 'workflow_dispatch',
                    'run_number' => 1,
                    'status' => 'in_progress',
                    'conclusion' => null,
                    'run_started_at' => '2026-04-29T11:55:00Z',
                    'updated_at' => '2026-04-29T11:56:00Z',
                    'html_url' => 'https://github.com/octocat/hello-world/actions/runs/5678',
                ],
            ],
        ]);

        $status = $this->handler()->handle($delivery);

        // Existing semantics preserved — Skipped means no activity event.
        $this->assertSame(WebhookDeliveryStatus::Skipped, $status);
        $this->assertSame(0, ActivityEvent::query()->count());
        // But the timeline upsert happens regardless so in-flight runs
        // surface on the Workflow Runs tab.
        $this->assertSame(1, WorkflowRun::query()->count());
        $run = WorkflowRun::query()->first();
        $this->assertSame('in_progress', $run->status->value);
        $this->assertNull($run->conclusion);
    }

    public function test_replayed_delivery_is_idempotent(): void
    {
        $this->importedRepository();
        $delivery = $this->deliveryFor('completed', 'success');

        $this->handler()->handle($delivery);
        $this->handler()->handle($delivery);

        $this->assertSame(1, WorkflowRun::query()->where('github_id', 1234)->count());
    }

    public function test_dispatches_workflow_run_upserted_event_on_upsert(): void
    {
        Event::fake([WorkflowRunUpserted::class]);
        $repository = $this->importedRepository();
        $delivery = $this->deliveryFor('completed', 'success');

        $this->handler()->handle($delivery);

        $run = WorkflowRun::query()->where('github_id', 1234)->firstOrFail();

        Event::assertDispatched(
            WorkflowRunUpserted::class,
            fn (WorkflowRunUpserted $event) => $event->runId === $run->id
                && $event->repositoryId === $repository->id
                && $event->ownerUserId === $repository->project->owner_user_id,
        );
    }

    public function test_dispatches_event_for_in_progress_runs_too(): void
    {
        Event::fake([WorkflowRunUpserted::class]);
        $this->importedRepository();
        $delivery = WebhookDelivery::factory()->create([
            'event' => 'workflow_run',
            'action' => 'in_progress',
            'repository_full_name' => 'octocat/hello-world',
            'payload_json' => [
                'action' => 'in_progress',
                'repository' => ['full_name' => 'octocat/hello-world'],
                'workflow_run' => [
                    'id' => 5678,
                    'name' => 'Deploy',
                    'head_branch' => 'main',
                    'head_sha' => 'b'.str_repeat('2', 39),
                    'event' => 'workflow_dispatch',
                    'run_number' => 1,
                    'status' => 'in_progress',
                    'conclusion' => null,
                    'run_started_at' => '2026-04-29T11:55:00Z',
                    'updated_at' => '2026-04-29T11:56:00Z',
                    'html_url' => 'https://github.com/octocat/hello-world/actions/runs/5678',
                ],
            ],
        ]);

        $this->handler()->handle($delivery);

        Event::assertDispatched(WorkflowRunUpserted::class);
    }

    public function test_does_not_dispatch_event_when_repository_not_imported(): void
    {
        Event::fake([WorkflowRunUpserted::class]);
        $delivery = $this->deliveryFor('completed', 'success', 'octocat/uh-oh');

        $this->handler()->handle($delivery);

        Event::assertNotDispatched(WorkflowRunUpserted::class);
    }
}
