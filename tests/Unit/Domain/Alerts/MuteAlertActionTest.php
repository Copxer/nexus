<?php

namespace Tests\Unit\Domain\Alerts;

use App\Domain\Alerts\Actions\MuteAlertAction;
use App\Enums\AlertStatus;
use App\Models\Alert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MuteAlertActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_alert_flips_to_muted(): void
    {
        $alert = Alert::factory()->create();

        app(MuteAlertAction::class)->execute($alert);

        $this->assertSame(AlertStatus::Muted, $alert->fresh()->status);
    }

    public function test_acknowledged_alert_can_still_be_muted(): void
    {
        $alert = Alert::factory()->acknowledged()->create();

        app(MuteAlertAction::class)->execute($alert);

        $this->assertSame(AlertStatus::Muted, $alert->fresh()->status);
    }

    public function test_already_muted_alert_is_left_alone(): void
    {
        $alert = Alert::factory()->muted()->create();
        $originalLastSeen = $alert->last_seen_at;

        $this->travel(2)->minutes();
        app(MuteAlertAction::class)->execute($alert);

        $alert->refresh();
        $this->assertSame(AlertStatus::Muted, $alert->status);
        $this->assertSame(
            $originalLastSeen?->toIso8601String(),
            $alert->last_seen_at?->toIso8601String(),
        );
    }

    public function test_resolved_alert_cannot_be_muted(): void
    {
        // Resolved is terminal — mute can't override (otherwise a
        // resolved-then-muted alert would silently re-open from a
        // future trigger inside the mute window, which is confusing).
        $alert = Alert::factory()->resolved()->create();

        app(MuteAlertAction::class)->execute($alert);

        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
    }
}
