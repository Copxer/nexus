<?php

namespace Tests\Feature\Monitoring;

use App\Domain\Monitoring\Actions\RunWebsiteProbeAction;
use App\Enums\WebsiteCheckStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunWebsiteProbeActionTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebsite(array $overrides = []): Website
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);

        return Website::factory()->create(array_merge([
            'project_id' => $project->id,
            'url' => 'https://example.com/health',
            'expected_status_code' => 200,
            'timeout_ms' => 10_000,
        ], $overrides));
    }

    public function test_classifies_matching_status_as_up(): void
    {
        Http::fake([
            'example.com/*' => Http::response('OK', 200),
        ]);

        $result = (new RunWebsiteProbeAction)->execute($this->makeWebsite());

        $this->assertSame(WebsiteCheckStatus::Up, $result->status);
        $this->assertSame(200, $result->httpStatusCode);
        $this->assertNotNull($result->responseTimeMs);
        $this->assertNull($result->errorMessage);
    }

    public function test_classifies_status_mismatch_as_down(): void
    {
        Http::fake([
            'example.com/*' => Http::response('Service Unavailable', 503),
        ]);

        $result = (new RunWebsiteProbeAction)->execute($this->makeWebsite());

        $this->assertSame(WebsiteCheckStatus::Down, $result->status);
        $this->assertSame(503, $result->httpStatusCode);
        $this->assertNotNull($result->errorMessage);
        $this->assertStringContainsString('503', $result->errorMessage);
    }

    public function test_classifies_slow_response_over_threshold(): void
    {
        // Fake a delayed response. Laravel's Http::fake doesn't sleep
        // by default, so we wrap the response in a callback that
        // usleep()s past the 3000ms threshold.
        Http::fake(function () {
            usleep(3_100_000); // 3.1 seconds

            return Http::response('OK', 200);
        });

        $result = (new RunWebsiteProbeAction)->execute($this->makeWebsite());

        $this->assertSame(WebsiteCheckStatus::Slow, $result->status);
        $this->assertSame(200, $result->httpStatusCode);
        $this->assertGreaterThan(3_000, $result->responseTimeMs);
        $this->assertNull($result->errorMessage);
    }

    public function test_classifies_transport_error(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out after 10000ms');
        });

        $result = (new RunWebsiteProbeAction)->execute($this->makeWebsite());

        $this->assertSame(WebsiteCheckStatus::Error, $result->status);
        $this->assertNull($result->httpStatusCode);
        $this->assertNull($result->responseTimeMs);
        $this->assertSame('Connection timed out after 10000ms', $result->errorMessage);
    }

    public function test_truncates_long_error_messages(): void
    {
        $longMessage = str_repeat('x', 800);

        Http::fake(function () use ($longMessage) {
            throw new ConnectionException($longMessage);
        });

        $result = (new RunWebsiteProbeAction)->execute($this->makeWebsite());

        $this->assertSame(WebsiteCheckStatus::Error, $result->status);
        // Str::limit($x, 500, '…') yields 500 + 1 ellipsis chars.
        $this->assertSame(501, mb_strlen($result->errorMessage));
        $this->assertStringEndsWith('…', $result->errorMessage);
    }

    public function test_uses_the_configured_http_method(): void
    {
        Http::fake([
            'example.com/*' => Http::response('', 200),
        ]);

        $website = $this->makeWebsite(['method' => 'HEAD']);
        (new RunWebsiteProbeAction)->execute($website);

        Http::assertSent(fn ($req) => $req->method() === 'HEAD');
    }
}
