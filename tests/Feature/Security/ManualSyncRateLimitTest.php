<?php

namespace Tests\Feature\Security;

use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Spec 039 — pin the rate-limit coverage on every manual sync /
 * retry / probe endpoint. Without these, a thumb-on-button user
 * could pump thousands of jobs into the queue or hammer GitHub
 * faster than spec 037's retry pipeline can recover.
 */
class ManualSyncRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function hammer(string $url, int $count, User $user): int
    {
        // Returns the HTTP status of the request that crosses the limit.
        for ($i = 1; $i <= $count; $i++) {
            $response = $this->actingAs($user)->post($url);
            if ($response->status() === 429) {
                return $i;
            }
        }

        return $count;
    }

    // No tearDown needed — each test creates a fresh `User`, and the
    // throttle bucket keys are derived from the user id, so state
    // never leaks between tests. PHPUnit's per-test isolation handles
    // the rest.

    public function test_repositories_sync_throttled_at_10_per_minute(): void
    {
        Bus::fake();
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'acme/api',
        ]);

        $url = route('repositories.sync', $repository->full_name);

        // Laravel's throttle middleware: requests 1–10 pass + increment,
        // request 11 hits `tooManyAttempts(10 >= 10)` → 429. Strict
        // equality so an off-by-one regression in a future Laravel
        // upgrade fails loudly.
        $crossed = $this->hammer($url, 12, $user);

        $this->assertSame(11, $crossed);
    }

    public function test_repositories_sync_all_throttled_at_2_per_minute(): void
    {
        Bus::fake();
        Queue::fake();
        $user = $this->verifiedUser();

        $url = route('repositories.sync-all');

        $crossed = $this->hammer($url, 4, $user);

        $this->assertSame(3, $crossed);
    }

    public function test_website_probe_throttled_at_20_per_minute(): void
    {
        Bus::fake();
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $website = Website::factory()->create(['project_id' => $project->id]);

        $url = route('monitoring.websites.probe', $website->id);

        $crossed = $this->hammer($url, 22, $user);

        $this->assertSame(21, $crossed);
    }

    public function test_webhook_delivery_retry_throttled_at_30_per_minute(): void
    {
        Bus::fake();
        $user = $this->verifiedUser();

        // Each request after the first finds the row in `received`
        // state and falls through to "Only failed deliveries can be
        // retried" — but the throttle still counts the attempts.
        $delivery = WebhookDelivery::factory()->create(['status' => 'failed']);
        $url = route('settings.webhook-deliveries.retry', $delivery->id);

        $crossed = $this->hammer($url, 32, $user);

        $this->assertSame(31, $crossed);
    }

    public function test_throttled_response_carries_retry_after_header(): void
    {
        Bus::fake();
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $repository = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'acme/api',
        ]);
        $url = route('repositories.sync', $repository->full_name);

        // Burn through the budget then trip.
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->post($url);
        }

        $response = $this->actingAs($user)->post($url);
        $response->assertStatus(429);
        $this->assertNotNull(
            $response->headers->get('Retry-After'),
            'Throttle middleware must set Retry-After so the UI can show "try again in N seconds".',
        );
    }
}
