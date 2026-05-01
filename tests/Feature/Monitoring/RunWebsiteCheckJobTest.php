<?php

namespace Tests\Feature\Monitoring;

use App\Domain\Monitoring\Actions\RecordWebsiteCheckAction;
use App\Domain\Monitoring\Actions\RunWebsiteProbeAction;
use App\Domain\Monitoring\Jobs\RunWebsiteCheckJob;
use App\Enums\WebsiteCheckStatus;
use App\Enums\WebsiteStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunWebsiteCheckJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_persists_a_check_and_updates_the_website(): void
    {
        Http::fake([
            'example.com/*' => Http::response('OK', 200),
        ]);

        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $website = Website::factory()->create([
            'project_id' => $project->id,
            'url' => 'https://example.com/health',
            'expected_status_code' => 200,
            'status' => WebsiteStatus::Pending->value,
        ]);

        (new RunWebsiteCheckJob($website->id))->handle(
            app(RunWebsiteProbeAction::class),
            app(RecordWebsiteCheckAction::class),
        );

        $this->assertSame(1, WebsiteCheck::query()->count());
        $check = WebsiteCheck::query()->first();
        $this->assertSame(WebsiteCheckStatus::Up, $check->status);

        $website->refresh();
        $this->assertSame(WebsiteStatus::Up, $website->status);
        $this->assertNotNull($website->last_checked_at);
        $this->assertNotNull($website->last_success_at);
    }

    public function test_handle_is_a_noop_when_website_was_deleted(): void
    {
        // No exception, no DB writes — the job just returns early when
        // the row was removed between dispatch and run.
        (new RunWebsiteCheckJob(999_999))->handle(
            app(RunWebsiteProbeAction::class),
            app(RecordWebsiteCheckAction::class),
        );

        $this->assertSame(0, WebsiteCheck::query()->count());
    }
}
