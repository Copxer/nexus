<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Actions\ComputeProjectHealthScoreAction;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\WebsiteStatus;
use App\Models\Alert;
use App\Models\Project;
use App\Models\ProjectHealthScoreWeightOverride;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 046 — per-user weight overrides drive the deduction math.
 * `null` on any override field falls back to the class default; the
 * existing `execute()` signature stays defaults-only for
 * backwards compatibility.
 */
class ComputeProjectHealthScoreActionWeightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_execute_still_uses_defaults_when_called_without_a_user(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        Alert::factory()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Critical->value,
            'status' => AlertStatus::Open->value,
        ]);

        // Regardless of any overrides the user set, `execute()` uses
        // the class defaults — DEDUCT_ALERT_CRITICAL = 30.
        ProjectHealthScoreWeightOverride::factory()->for($user)->create([
            'deduct_alert_critical' => 10,
        ]);

        $score = app(ComputeProjectHealthScoreAction::class)->execute($project);

        // 100 - 30 (default weight) = 70.
        $this->assertSame(70, $score);
    }

    public function test_execute_for_user_applies_override_when_present(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        Alert::factory()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Critical->value,
            'status' => AlertStatus::Open->value,
        ]);

        ProjectHealthScoreWeightOverride::factory()->for($user)->create([
            'deduct_alert_critical' => 10,
        ]);

        $score = app(ComputeProjectHealthScoreAction::class)
            ->executeForUser($project, $user);

        $this->assertSame(90, $score);
    }

    public function test_execute_for_user_falls_back_to_default_when_field_null(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        // Override row exists but the field we care about is null →
        // fall back to the DEDUCT_WEBSITE_DOWN default (20).
        ProjectHealthScoreWeightOverride::factory()->for($user)->create([
            'deduct_alert_critical' => 10,
            'deduct_website_down' => null,
        ]);

        Website::factory()->create([
            'project_id' => $project->id,
            'status' => WebsiteStatus::Down->value,
        ]);

        $score = app(ComputeProjectHealthScoreAction::class)
            ->executeForUser($project, $user);

        // Only website deduction applies, and it uses the 20 default.
        $this->assertSame(80, $score);
    }

    public function test_execute_for_user_uses_defaults_when_user_has_no_override_row(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);

        Alert::factory()->create([
            'project_id' => $project->id,
            'severity' => AlertSeverity::Warning->value,
            'status' => AlertStatus::Open->value,
        ]);

        $score = app(ComputeProjectHealthScoreAction::class)
            ->executeForUser($project, $user);

        // 100 - 15 (default DEDUCT_ALERT_WARNING) = 85.
        $this->assertSame(85, $score);
    }
}
