<?php

namespace Tests\Feature\PublicStatus;

use App\Mail\PublicStatusSubscribeConfirmationMail;
use App\Models\Project;
use App\Models\PublicStatusSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PublicStatusSubscribeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_subscription_creates_row_and_sends_confirmation(): void
    {
        Mail::fake();

        $project = Project::factory()->create(['public_status_enabled' => true]);

        $this->post(
            route('public-status.subscribe', ['project' => $project->slug]),
            ['email' => 'watcher@example.com'],
        )->assertRedirect();

        $subscriber = PublicStatusSubscriber::query()
            ->where('project_id', $project->id)
            ->where('email', 'watcher@example.com')
            ->firstOrFail();

        $this->assertNull($subscriber->confirmed_at);
        Mail::assertSent(PublicStatusSubscribeConfirmationMail::class);
    }

    public function test_duplicate_email_refreshes_tokens_without_creating_second_row(): void
    {
        Mail::fake();

        $project = Project::factory()->create(['public_status_enabled' => true]);
        $existing = PublicStatusSubscriber::factory()
            ->for($project)
            ->create(['email' => 'watcher@example.com']);
        $originalToken = $existing->confirmation_token;

        $this->post(
            route('public-status.subscribe', ['project' => $project->slug]),
            ['email' => 'watcher@example.com'],
        )->assertRedirect();

        $this->assertSame(
            1,
            PublicStatusSubscriber::query()
                ->where('project_id', $project->id)
                ->where('email', 'watcher@example.com')
                ->count(),
        );
        $this->assertNotSame($originalToken, $existing->fresh()->confirmation_token);
    }

    public function test_honeypot_submission_silently_succeeds_without_creating_row(): void
    {
        Mail::fake();

        $project = Project::factory()->create(['public_status_enabled' => true]);

        $this->post(
            route('public-status.subscribe', ['project' => $project->slug]),
            [
                'email' => 'bot@example.com',
                'honeypot' => 'gotcha',
            ],
        )->assertRedirect();

        $this->assertSame(0, PublicStatusSubscriber::query()->count());
        Mail::assertNothingSent();
    }

    public function test_invalid_email_422s(): void
    {
        $project = Project::factory()->create(['public_status_enabled' => true]);

        $this->post(
            route('public-status.subscribe', ['project' => $project->slug]),
            ['email' => 'not-an-email'],
        )->assertSessionHasErrors('email');
    }

    public function test_subscribe_404s_when_project_disabled(): void
    {
        $project = Project::factory()->create(['public_status_enabled' => false]);

        $this->post(
            route('public-status.subscribe', ['project' => $project->slug]),
            ['email' => 'watcher@example.com'],
        )->assertNotFound();
    }
}
