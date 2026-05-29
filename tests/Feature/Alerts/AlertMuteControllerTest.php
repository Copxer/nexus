<?php

namespace Tests\Feature\Alerts;

use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertMuteControllerTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_owner_can_mute_an_open_alert(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $alert = Alert::factory()->create(['project_id' => $project->id]);

        $response = $this->actingAs($user)->post(route('alerts.mute', $alert->id));

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Alert muted.');
        $this->assertSame(AlertStatus::Muted, $alert->fresh()->status);
    }

    public function test_already_muted_alert_stays_muted(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $alert = Alert::factory()->muted()->create(['project_id' => $project->id]);
        $originalLastSeen = $alert->last_seen_at;

        $this->travel(1)->minute();
        $this->actingAs($user)->post(route('alerts.mute', $alert->id));

        $alert->refresh();
        $this->assertSame(AlertStatus::Muted, $alert->status);
        $this->assertSame(
            $originalLastSeen?->toIso8601String(),
            $alert->last_seen_at?->toIso8601String(),
        );
    }

    public function test_resolved_alert_cannot_be_muted(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $alert = Alert::factory()->resolved()->create(['project_id' => $project->id]);

        $this->actingAs($user)->post(route('alerts.mute', $alert->id));

        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
    }

    public function test_sibling_user_is_forbidden(): void
    {
        $user = $this->verifiedUser();
        $other = $this->verifiedUser();
        $othersProject = Project::factory()->create(['owner_user_id' => $other->id]);
        $alert = Alert::factory()->create(['project_id' => $othersProject->id]);

        $this->actingAs($user)
            ->post(route('alerts.mute', $alert->id))
            ->assertForbidden();

        $this->assertSame(AlertStatus::Open, $alert->fresh()->status);
    }
}
