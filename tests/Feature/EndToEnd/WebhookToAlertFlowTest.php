<?php

namespace Tests\Feature\EndToEnd;

use App\Domain\GitHub\Jobs\ProcessGitHubWebhookJob;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 040 — end-to-end: GitHub `workflow_run` webhook with
 * `conclusion: failure` on the default branch arrives →
 * processed → workflow_run row upserted → activity event
 * `workflow.failed` fires → Alert opens via the same
 * transition path → user resolves the alert via the lifecycle
 * endpoint.
 *
 * Pins the contract that:
 *   - `POST /webhooks/github` accepts the signed payload + writes a
 *     delivery row.
 *   - `ProcessGitHubWebhookJob` routes to `WorkflowRunWebhookHandler`
 *     + upserts `workflow_runs` + fires the activity event.
 *   - The same handler promotes a default-branch failure into an
 *     open `AlertSource::Deployment` alert.
 *   - `POST /alerts/{alert}/resolve` flips the alert to Resolved.
 */
class WebhookToAlertFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_failed_webhook_promotes_to_alert_and_user_resolves(): void
    {
        $user = $this->verifiedUser();
        [$project, $repository] = $this->projectWithRepository($user, [
            'full_name' => 'spec040/api',
            'default_branch' => 'main',
        ]);

        // 1. Signed webhook → controller writes delivery row + dispatches job.
        $payload = [
            'action' => 'completed',
            'repository' => ['full_name' => $repository->full_name],
            'workflow_run' => [
                'id' => 9999,
                'name' => 'CI',
                'head_branch' => 'main',
                'head_sha' => 'a'.str_repeat('1', 39),
                'event' => 'push',
                'run_number' => 7,
                'conclusion' => 'failure',
                'status' => 'completed',
                'updated_at' => now()->toIso8601String(),
                'run_started_at' => now()->subMinutes(5)->toIso8601String(),
                'html_url' => "https://github.com/{$repository->full_name}/actions/runs/9999",
                'actor' => ['login' => 'bob'],
            ],
        ];

        [$headers, $body] = $this->signedGitHubWebhook('workflow_run', $payload, 'delivery-spec040');

        $this->call('POST', route('webhooks.github'), [], [], [], $headers, $body)
            ->assertStatus(200);

        $delivery = WebhookDelivery::query()
            ->where('github_delivery_id', 'delivery-spec040')
            ->firstOrFail();

        // 2. Run the job inline (the controller dispatches it via the
        //    queue; we exercise the handler chain directly to assert
        //    the persisted state).
        (new ProcessGitHubWebhookJob($delivery->id))->handle();

        // 3. workflow_runs row upserted + Alert opened.
        $this->assertDatabaseHas('workflow_runs', [
            'repository_id' => $repository->id,
            'github_id' => 9999,
            'conclusion' => 'failure',
        ]);

        $alert = Alert::query()
            ->where('source', AlertSource::Deployment->value)
            ->where('type', 'workflow.failed')
            ->firstOrFail();
        $this->assertSame(AlertStatus::Open, $alert->status);
        $this->assertSame($project->id, $alert->project_id);

        // 4. User resolves the alert via the lifecycle endpoint.
        $this->actingAs($user)
            ->post(route('alerts.resolve', $alert->id))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
        $this->assertNotNull($alert->fresh()->resolved_at);
    }
}
