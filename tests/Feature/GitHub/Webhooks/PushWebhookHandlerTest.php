<?php

namespace Tests\Feature\GitHub\Webhooks;

use App\Domain\GitHub\WebhookHandlers\PushWebhookHandler;
use App\Enums\WebhookDeliveryStatus;
use App\Models\ActivityEvent;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushWebhookHandlerTest extends TestCase
{
    use RefreshDatabase;

    private function handler(): PushWebhookHandler
    {
        return new PushWebhookHandler;
    }

    private function deliveryFor(string $iso, string $fullName = 'octocat/hello-world'): WebhookDelivery
    {
        return WebhookDelivery::factory()->create([
            'event' => 'push',
            'action' => null,
            'repository_full_name' => $fullName,
            'payload_json' => [
                'repository' => ['full_name' => $fullName],
                'head_commit' => [
                    'id' => 'abc123',
                    'message' => 'Test commit',
                    'timestamp' => $iso,
                ],
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
            'last_pushed_at' => null,
        ]);
    }

    public function test_push_updates_last_pushed_at_without_creating_activity(): void
    {
        $repo = $this->importedRepository();
        $delivery = $this->deliveryFor('2026-04-29T12:00:00Z');

        $status = $this->handler()->handle($delivery);

        $this->assertSame(WebhookDeliveryStatus::Processed, $status);
        $this->assertSame(0, ActivityEvent::query()->count(), 'pushes intentionally do not surface to activity');

        $repo->refresh();
        $this->assertNotNull($repo->last_pushed_at);
        $this->assertSame('2026-04-29 12:00:00', $repo->last_pushed_at->format('Y-m-d H:i:s'));
    }

    public function test_push_to_unimported_repo_is_skipped(): void
    {
        $delivery = $this->deliveryFor('2026-04-29T12:00:00Z', 'octocat/missing');

        $status = $this->handler()->handle($delivery);

        $this->assertSame(WebhookDeliveryStatus::Skipped, $status);
    }

    public function test_push_without_timestamp_is_skipped(): void
    {
        $this->importedRepository();
        $delivery = WebhookDelivery::factory()->create([
            'event' => 'push',
            'action' => null,
            'repository_full_name' => 'octocat/hello-world',
            'payload_json' => [
                'repository' => ['full_name' => 'octocat/hello-world'],
            ],
        ]);

        $status = $this->handler()->handle($delivery);

        $this->assertSame(WebhookDeliveryStatus::Skipped, $status);
    }
}
