<?php

namespace Tests\Feature\Monitoring;

use App\Domain\Monitoring\Jobs\DispatchDueWebsiteChecksJob;
use App\Domain\Monitoring\Jobs\RunWebsiteCheckJob;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchDueWebsiteChecksJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebsite(array $overrides = []): Website
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        return Website::factory()->create(array_merge([
            'project_id' => $project->id,
            'check_interval_seconds' => 300,
        ], $overrides));
    }

    public function test_dispatches_when_website_was_never_checked(): void
    {
        Queue::fake();
        $website = $this->makeWebsite(['last_checked_at' => null]);

        (new DispatchDueWebsiteChecksJob)->handle();

        Queue::assertPushed(
            RunWebsiteCheckJob::class,
            fn (RunWebsiteCheckJob $job) => $job->websiteId === $website->id,
        );
    }

    public function test_dispatches_when_interval_has_elapsed(): void
    {
        Queue::fake();
        $this->makeWebsite([
            'last_checked_at' => now()->subSeconds(310), // > 300s interval
            'check_interval_seconds' => 300,
        ]);

        (new DispatchDueWebsiteChecksJob)->handle();

        Queue::assertPushed(RunWebsiteCheckJob::class);
    }

    public function test_does_not_dispatch_when_check_is_recent(): void
    {
        Queue::fake();
        $this->makeWebsite([
            'last_checked_at' => now()->subSeconds(60), // < 300s interval
            'check_interval_seconds' => 300,
        ]);

        (new DispatchDueWebsiteChecksJob)->handle();

        Queue::assertNotPushed(RunWebsiteCheckJob::class);
    }

    public function test_filters_per_website_intervals_independently(): void
    {
        Queue::fake();

        // Due — 60s interval, last checked 90s ago.
        $due = $this->makeWebsite([
            'last_checked_at' => now()->subSeconds(90),
            'check_interval_seconds' => 60,
        ]);
        // Not due — 3600s interval, last checked 600s ago.
        $notDue = $this->makeWebsite([
            'last_checked_at' => now()->subSeconds(600),
            'check_interval_seconds' => 3600,
        ]);

        (new DispatchDueWebsiteChecksJob)->handle();

        Queue::assertPushed(
            RunWebsiteCheckJob::class,
            fn (RunWebsiteCheckJob $job) => $job->websiteId === $due->id,
        );
        Queue::assertNotPushed(
            RunWebsiteCheckJob::class,
            fn (RunWebsiteCheckJob $job) => $job->websiteId === $notDue->id,
        );
    }

    public function test_does_nothing_with_no_websites(): void
    {
        Queue::fake();

        (new DispatchDueWebsiteChecksJob)->handle();

        Queue::assertNothingPushed();
    }
}
