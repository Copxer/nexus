<?php

namespace Tests\Feature\GitHub\Webhooks;

use App\Domain\Activity\Actions\CreateActivityEventAction;
use App\Domain\GitHub\WebhookHandlers\ReleaseWebhookHandler;
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

class ReleaseWebhookHandlerTest extends TestCase
{
    use RefreshDatabase;

    private function handler(): ReleaseWebhookHandler
    {
        return new ReleaseWebhookHandler(new CreateActivityEventAction);
    }

    private function deliveryFor(
        string $action,
        array $releaseOverrides = [],
        string $fullName = 'octocat/hello-world',
    ): WebhookDelivery {
        return WebhookDelivery::factory()->create([
            'event' => 'release',
            'action' => $action,
            'repository_full_name' => $fullName,
            'payload_json' => [
                'action' => $action,
                'repository' => ['full_name' => $fullName],
                'release' => array_merge([
                    'id' => 1234,
                    'tag_name' => 'v1.0.0',
                    'name' => 'Initial release',
                    'draft' => false,
                    'prerelease' => false,
                    'published_at' => '2026-04-29T12:00:00Z',
                    'created_at' => '2026-04-29T11:55:00Z',
                    'html_url' => 'https://github.com/octocat/hello-world/releases/tag/v1.0.0',
                ], $releaseOverrides),
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

    public function test_released_action_creates_release_published_activity(): void
    {
        Event::fake([ActivityEventCreated::class]);
        $this->importedRepository();
        $delivery = $this->deliveryFor('released');

        $status = $this->handler()->handle($delivery);

        $this->assertSame(WebhookDeliveryStatus::Processed, $status);
        $this->assertDatabaseHas('activity_events', [
            'event_type' => 'release.published',
            'severity' => 'info',
        ]);
        Event::assertDispatched(ActivityEventCreated::class);
    }

    public function test_published_action_alias_also_processes(): void
    {
        $this->importedRepository();
        $delivery = $this->deliveryFor('published');

        $status = $this->handler()->handle($delivery);

        $this->assertSame(WebhookDeliveryStatus::Processed, $status);
        $this->assertSame(1, ActivityEvent::query()->count());
    }

    public function test_draft_release_is_skipped(): void
    {
        $this->importedRepository();
        $delivery = $this->deliveryFor('released', ['draft' => true]);

        $status = $this->handler()->handle($delivery);

        $this->assertSame(WebhookDeliveryStatus::Skipped, $status);
        $this->assertSame(0, ActivityEvent::query()->count());
    }

    public function test_unhandled_action_is_skipped(): void
    {
        $this->importedRepository();
        $delivery = $this->deliveryFor('edited');

        $status = $this->handler()->handle($delivery);

        $this->assertSame(WebhookDeliveryStatus::Skipped, $status);
    }

    public function test_unimported_repository_is_skipped(): void
    {
        $delivery = $this->deliveryFor('released', [], 'octocat/missing');

        $status = $this->handler()->handle($delivery);

        $this->assertSame(WebhookDeliveryStatus::Skipped, $status);
    }
}
