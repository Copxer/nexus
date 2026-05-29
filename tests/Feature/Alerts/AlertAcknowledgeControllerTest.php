<?php

namespace Tests\Feature\Alerts;

use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertAcknowledgeControllerTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_owner_can_acknowledge_an_open_alert(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $alert = Alert::factory()->create(['project_id' => $project->id]);

        $response = $this->actingAs($user)
            ->post(route('alerts.acknowledge', $alert->id));

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Alert acknowledged.');
        $this->assertSame(AlertStatus::Acknowledged, $alert->fresh()->status);
    }

    public function test_re_acknowledging_is_idempotent(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $alert = Alert::factory()->acknowledged()->create(['project_id' => $project->id]);
        $originalAck = $alert->acknowledged_at;

        $this->travel(1)->minute();
        $this->actingAs($user)->post(route('alerts.acknowledge', $alert->id));

        $alert->refresh();
        $this->assertSame(AlertStatus::Acknowledged, $alert->status);
        $this->assertSame(
            $originalAck?->toIso8601String(),
            $alert->acknowledged_at?->toIso8601String(),
        );
    }

    public function test_sibling_user_is_forbidden(): void
    {
        $user = $this->verifiedUser();
        $other = $this->verifiedUser();
        $othersProject = Project::factory()->create(['owner_user_id' => $other->id]);
        $alert = Alert::factory()->create(['project_id' => $othersProject->id]);

        $this->actingAs($user)
            ->post(route('alerts.acknowledge', $alert->id))
            ->assertForbidden();

        $this->assertSame(AlertStatus::Open, $alert->fresh()->status);
    }

    public function test_guests_cannot_acknowledge(): void
    {
        $alert = Alert::factory()->create();

        $this->post(route('alerts.acknowledge', $alert->id))
            ->assertRedirect(route('login'));
    }
}
