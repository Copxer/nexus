<?php

namespace Tests\Unit\Domain\Alerts;

use App\Domain\Alerts\Actions\AcknowledgeAlertAction;
use App\Enums\AlertStatus;
use App\Models\Alert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcknowledgeAlertActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_alert_flips_to_acknowledged_and_stamps(): void
    {
        $alert = Alert::factory()->create();

        $result = app(AcknowledgeAlertAction::class)->execute($alert);

        $this->assertSame($alert->id, $result->id);
        $result->refresh();
        $this->assertSame(AlertStatus::Acknowledged, $result->status);
        $this->assertNotNull($result->acknowledged_at);
        $this->assertNotNull($result->last_seen_at);
    }

    public function test_already_acknowledged_alert_is_left_alone(): void
    {
        $alert = Alert::factory()->acknowledged()->create();
        $originalAck = $alert->acknowledged_at;

        $this->travel(2)->minutes();
        app(AcknowledgeAlertAction::class)->execute($alert);

        $alert->refresh();
        $this->assertSame(
            $originalAck?->toIso8601String(),
            $alert->acknowledged_at?->toIso8601String(),
            'no double-stamp on re-ack',
        );
    }

    public function test_resolved_alert_cannot_be_acknowledged(): void
    {
        $alert = Alert::factory()->resolved()->create();

        app(AcknowledgeAlertAction::class)->execute($alert);

        $this->assertSame(AlertStatus::Resolved, $alert->fresh()->status);
    }

    public function test_muted_alert_cannot_be_acknowledged(): void
    {
        $alert = Alert::factory()->muted()->create();

        app(AcknowledgeAlertAction::class)->execute($alert);

        $this->assertSame(AlertStatus::Muted, $alert->fresh()->status);
    }
}
