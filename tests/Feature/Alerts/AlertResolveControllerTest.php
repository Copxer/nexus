<?php

namespace Tests\Feature\Alerts;

use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\ActivityEvent;
use App\Models\Alert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertResolveControllerTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_owner_can_resolve_an_open_alert_and_emits_activity(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $alert = Alert::factory()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Website->value,
            'source_id' => 42,
            'type' => 'website.down',
            'title' => 'Marketing site',
        ]);

        $response = $this->actingAs($user)
            ->post(route('alerts.resolve', $alert->id));

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Alert resolved.');
        $alert->refresh();
        $this->assertSame(AlertStatus::Resolved, $alert->status);
        $this->assertNotNull($alert->resolved_at);

        $event = ActivityEvent::query()
            ->where('event_type', 'alert.resolved')
            ->firstOrFail();
        $this->assertSame('alerts', $event->source);
        $this->assertSame($alert->id, $event->metadata['alert_id']);
        $this->assertSame('website', $event->metadata['alert_source']);
        $this->assertSame(42, $event->metadata['alert_source_id']);
        $this->assertSame('Marketing site resolved', $event->title);
    }

    public function test_resolve_refuses_a_muted_alert_with_a_clear_error(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $alert = Alert::factory()->muted()->create(['project_id' => $project->id]);

        $response = $this->actingAs($user)
            ->post(route('alerts.resolve', $alert->id));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(AlertStatus::Muted, $alert->fresh()->status);
        $this->assertSame(
            0,
            ActivityEvent::query()
                ->where('event_type', 'alert.resolved')
                ->count(),
            'no spurious activity event on the refused resolve',
        );
    }

    public function test_acknowledged_alert_can_be_resolved(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $alert = Alert::factory()->acknowledged()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Docker->value,
            'source_id' => 5,
            'type' => 'host.offline',
        ]);
        $originalAck = $alert->acknowledged_at;

        $this->actingAs($user)->post(route('alerts.resolve', $alert->id));

        $alert->refresh();
        $this->assertSame(AlertStatus::Resolved, $alert->status);
        $this->assertNotNull($alert->resolved_at);
        $this->assertSame(
            $originalAck?->toIso8601String(),
            $alert->acknowledged_at?->toIso8601String(),
            'acknowledged_at is preserved through resolve',
        );
        $this->assertSame(
            1,
            ActivityEvent::query()
                ->where('event_type', 'alert.resolved')
                ->count(),
        );
    }

    public function test_resolving_an_already_resolved_alert_is_a_noop(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $alert = Alert::factory()->resolved()->create([
            'project_id' => $project->id,
            'source' => AlertSource::Website->value,
            'source_id' => 1,
            'type' => 'website.down',
        ]);

        $this->actingAs($user)->post(route('alerts.resolve', $alert->id));

        // Filter by event_type so the assertion stays sharp even if
        // the action ever starts emitting other event types.
        $this->assertSame(
            0,
            ActivityEvent::query()
                ->where('event_type', 'alert.resolved')
                ->count(),
            'no spurious alert.resolved on re-resolve',
        );
        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
    }

    public function test_sibling_user_is_forbidden(): void
    {
        $user = $this->verifiedUser();
        $other = $this->verifiedUser();
        $othersProject = Project::factory()->create(['owner_user_id' => $other->id]);
        $alert = Alert::factory()->create(['project_id' => $othersProject->id]);

        $this->actingAs($user)
            ->post(route('alerts.resolve', $alert->id))
            ->assertForbidden();

        $this->assertSame(AlertStatus::Open, $alert->fresh()->status);
    }
}
