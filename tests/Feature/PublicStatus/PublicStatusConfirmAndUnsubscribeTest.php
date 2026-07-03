<?php

namespace Tests\Feature\PublicStatus;

use App\Models\Project;
use App\Models\PublicStatusSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicStatusConfirmAndUnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_flips_confirmed_at(): void
    {
        $project = Project::factory()->create(['public_status_enabled' => true]);
        $subscriber = PublicStatusSubscriber::factory()->for($project)->create();

        $this->get(route('public-status.confirm', [
            'project' => $project->slug,
            'token' => $subscriber->confirmation_token,
        ]))->assertOk();

        $this->assertNotNull($subscriber->fresh()->confirmed_at);
    }

    public function test_confirm_is_idempotent_on_repeat_hit(): void
    {
        $project = Project::factory()->create(['public_status_enabled' => true]);
        $subscriber = PublicStatusSubscriber::factory()
            ->for($project)
            ->confirmed()
            ->create();

        $firstConfirmedAt = $subscriber->confirmed_at;

        $this->get(route('public-status.confirm', [
            'project' => $project->slug,
            'token' => $subscriber->confirmation_token,
        ]))->assertOk();

        // confirmed_at wasn't clobbered by a re-visit.
        $this->assertEquals(
            $firstConfirmedAt->timestamp,
            $subscriber->fresh()->confirmed_at->timestamp,
        );
    }

    public function test_confirm_unknown_token_404s(): void
    {
        $project = Project::factory()->create(['public_status_enabled' => true]);

        $this->get(route('public-status.confirm', [
            'project' => $project->slug,
            'token' => str_repeat('x', 64),
        ]))->assertNotFound();
    }

    public function test_unsubscribe_deletes_the_row(): void
    {
        $project = Project::factory()->create(['public_status_enabled' => true]);
        $subscriber = PublicStatusSubscriber::factory()->for($project)->confirmed()->create();

        $this->get(route('public-status.unsubscribe', [
            'token' => $subscriber->unsubscribe_token,
        ]))->assertOk();

        $this->assertNull(PublicStatusSubscriber::query()->find($subscriber->id));
    }

    public function test_unsubscribe_unknown_token_404s(): void
    {
        $this->get(route('public-status.unsubscribe', [
            'token' => str_repeat('y', 64),
        ]))->assertNotFound();
    }
}
