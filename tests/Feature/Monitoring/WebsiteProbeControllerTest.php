<?php

namespace Tests\Feature\Monitoring;

use App\Enums\WebsiteCheckStatus;
use App\Enums\WebsiteStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebsiteProbeControllerTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_owner_can_probe_and_a_check_is_persisted(): void
    {
        Http::fake(['example.com/*' => Http::response('OK', 200)]);

        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $website = Website::factory()->create([
            'project_id' => $project->id,
            'url' => 'https://example.com/health',
            'expected_status_code' => 200,
        ]);

        $this->actingAs($user)
            ->from(route('monitoring.websites.show', $website))
            ->post(route('monitoring.websites.probe', $website))
            ->assertRedirect(route('monitoring.websites.show', $website))
            ->assertSessionHas('status');

        $this->assertSame(1, WebsiteCheck::query()->count());
        $check = WebsiteCheck::query()->first();
        $this->assertSame(WebsiteCheckStatus::Up, $check->status);

        $website->refresh();
        $this->assertSame(WebsiteStatus::Up, $website->status);
        $this->assertNotNull($website->last_checked_at);
    }

    public function test_non_owner_is_forbidden(): void
    {
        $owner = $this->verifiedUser();
        $other = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $website = Website::factory()->create(['project_id' => $project->id]);

        $this->actingAs($other)
            ->post(route('monitoring.websites.probe', $website))
            ->assertForbidden();

        $this->assertSame(0, WebsiteCheck::query()->count());
    }

    public function test_unknown_website_returns_404(): void
    {
        $user = $this->verifiedUser();

        $this->actingAs($user)
            ->post(route('monitoring.websites.probe', 999_999))
            ->assertNotFound();
    }
}
