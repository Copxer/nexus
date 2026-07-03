<?php

namespace Tests\Feature\PublicStatus;

use App\Domain\PublicStatus\Jobs\NotifyStatusSubscribersJob;
use App\Mail\PublicStatusIncidentMail;
use App\Models\Alert;
use App\Models\Project;
use App\Models\PublicStatusSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotifyStatusSubscribersJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_confirmed_subscribers_receive_mail(): void
    {
        Mail::fake();

        $project = Project::factory()->create(['public_status_enabled' => true]);
        $confirmed = PublicStatusSubscriber::factory()->for($project)->confirmed()->create([
            'email' => 'watcher@example.com',
        ]);
        PublicStatusSubscriber::factory()->for($project)->create([
            'email' => 'pending@example.com',
        ]);
        $alert = Alert::factory()->create(['project_id' => $project->id]);

        (new NotifyStatusSubscribersJob($alert->id, 'triggered'))->handle();

        Mail::assertSent(PublicStatusIncidentMail::class, 1);
        Mail::assertSent(PublicStatusIncidentMail::class, fn ($mail) => $mail->hasTo('watcher@example.com'));
        Mail::assertNotSent(
            PublicStatusIncidentMail::class,
            fn ($mail) => $mail->hasTo('pending@example.com'),
        );
    }

    public function test_disabled_project_skips_send(): void
    {
        Mail::fake();

        $project = Project::factory()->create(['public_status_enabled' => false]);
        PublicStatusSubscriber::factory()->for($project)->confirmed()->create();
        $alert = Alert::factory()->create(['project_id' => $project->id]);

        (new NotifyStatusSubscribersJob($alert->id, 'triggered'))->handle();

        Mail::assertNothingSent();
    }

    public function test_no_confirmed_subscribers_is_silent(): void
    {
        Mail::fake();

        $project = Project::factory()->create(['public_status_enabled' => true]);
        // Only pending subscribers.
        PublicStatusSubscriber::factory()->for($project)->count(3)->create();
        $alert = Alert::factory()->create(['project_id' => $project->id]);

        (new NotifyStatusSubscribersJob($alert->id, 'triggered'))->handle();

        Mail::assertNothingSent();
    }

    public function test_missing_alert_row_is_silent(): void
    {
        Mail::fake();

        (new NotifyStatusSubscribersJob(999999, 'triggered'))->handle();

        Mail::assertNothingSent();
    }
}
