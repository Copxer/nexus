<?php

namespace Tests\Feature\Monitoring;

use App\Domain\Monitoring\Actions\RecordWebsiteCheckAction;
use App\Domain\Monitoring\Probes\WebsiteProbeResult;
use App\Enums\WebsiteCheckStatus;
use App\Enums\WebsiteStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordWebsiteCheckActionTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebsite(): Website
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        return Website::factory()->create([
            'project_id' => $project->id,
            'status' => WebsiteStatus::Pending->value,
        ]);
    }

    public function test_persists_a_check_row_and_returns_it(): void
    {
        $website = $this->makeWebsite();
        $result = new WebsiteProbeResult(
            status: WebsiteCheckStatus::Up,
            httpStatusCode: 200,
            responseTimeMs: 142,
            errorMessage: null,
        );

        $check = (new RecordWebsiteCheckAction)->execute($website, $result);

        $this->assertInstanceOf(WebsiteCheck::class, $check);
        $this->assertSame(1, WebsiteCheck::query()->count());
        $this->assertSame(WebsiteCheckStatus::Up, $check->status);
        $this->assertSame(200, $check->http_status_code);
        $this->assertSame(142, $check->response_time_ms);
    }

    public function test_up_result_updates_status_and_last_success_at(): void
    {
        $website = $this->makeWebsite();
        $result = new WebsiteProbeResult(
            status: WebsiteCheckStatus::Up,
            httpStatusCode: 200,
            responseTimeMs: 100,
            errorMessage: null,
        );

        (new RecordWebsiteCheckAction)->execute($website, $result);

        $website->refresh();
        $this->assertSame(WebsiteStatus::Up, $website->status);
        $this->assertNotNull($website->last_checked_at);
        $this->assertNotNull($website->last_success_at);
        $this->assertNull($website->last_failure_at);
    }

    public function test_slow_result_counts_as_success_for_last_success_at(): void
    {
        $website = $this->makeWebsite();
        $result = new WebsiteProbeResult(
            status: WebsiteCheckStatus::Slow,
            httpStatusCode: 200,
            responseTimeMs: 4_200,
            errorMessage: null,
        );

        (new RecordWebsiteCheckAction)->execute($website, $result);

        $website->refresh();
        $this->assertSame(WebsiteStatus::Slow, $website->status);
        $this->assertNotNull($website->last_success_at);
        $this->assertNull($website->last_failure_at);
    }

    public function test_down_result_updates_status_and_last_failure_at(): void
    {
        $website = $this->makeWebsite();
        $result = new WebsiteProbeResult(
            status: WebsiteCheckStatus::Down,
            httpStatusCode: 503,
            responseTimeMs: 220,
            errorMessage: 'HTTP 503: Service Unavailable',
        );

        (new RecordWebsiteCheckAction)->execute($website, $result);

        $website->refresh();
        $this->assertSame(WebsiteStatus::Down, $website->status);
        $this->assertNotNull($website->last_checked_at);
        $this->assertNull($website->last_success_at);
        $this->assertNotNull($website->last_failure_at);
    }

    public function test_error_result_updates_status_and_last_failure_at(): void
    {
        $website = $this->makeWebsite();
        $result = new WebsiteProbeResult(
            status: WebsiteCheckStatus::Error,
            httpStatusCode: null,
            responseTimeMs: null,
            errorMessage: 'Connection timed out after 10000ms',
        );

        (new RecordWebsiteCheckAction)->execute($website, $result);

        $website->refresh();
        $this->assertSame(WebsiteStatus::Error, $website->status);
        $this->assertNotNull($website->last_failure_at);
        $this->assertNull($website->last_success_at);
    }

    public function test_subsequent_check_does_not_clobber_prior_success_timestamp(): void
    {
        // A successful run, then a failure: last_success_at must be
        // preserved (it's "last successful probe", not "last probe
        // when status was Up"). The same rule applies in reverse.
        $website = $this->makeWebsite();

        (new RecordWebsiteCheckAction)->execute(
            $website,
            new WebsiteProbeResult(WebsiteCheckStatus::Up, 200, 100, null),
        );
        $firstSuccess = $website->fresh()->last_success_at;

        // Sleep so the timestamps differ enough to compare reliably.
        sleep(1);

        (new RecordWebsiteCheckAction)->execute(
            $website,
            new WebsiteProbeResult(WebsiteCheckStatus::Down, 500, 150, 'HTTP 500'),
        );

        $fresh = $website->fresh();
        $this->assertEquals($firstSuccess->toIso8601String(), $fresh->last_success_at->toIso8601String());
        $this->assertNotNull($fresh->last_failure_at);
        $this->assertSame(WebsiteStatus::Down, $fresh->status);
    }
}
