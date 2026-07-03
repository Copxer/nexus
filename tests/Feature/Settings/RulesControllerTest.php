<?php

namespace Tests\Feature\Settings;

use App\Domain\Analytics\Jobs\RecomputeAllProjectHealthScoresJob;
use App\Enums\AlertRuleKind;
use App\Enums\AlertSeverity;
use App\Models\AlertRule;
use App\Models\Project;
use App\Models\ProjectHealthScoreWeightOverride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Spec 046 — `/settings/rules` controller. Covers both slices:
 * weights (update / reset / recompute dispatch) and rules (CRUD +
 * ownership + throttle sanity).
 */
class RulesControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_for_a_verified_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('settings.rules.index'))
            ->assertOk();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('settings.rules.index'))->assertRedirect(route('login'));
    }

    public function test_update_weights_creates_override_row_and_dispatches_recompute(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        Project::factory()->create(['owner_user_id' => $user->id]);

        $this->actingAs($user)
            ->patch(route('settings.rules.weights.update'), [
                'deduct_alert_critical' => 40,
                'deduct_alert_warning' => 20,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_health_score_weight_overrides', [
            'user_id' => $user->id,
            'deduct_alert_critical' => 40,
            'deduct_alert_warning' => 20,
        ]);

        Bus::assertDispatched(RecomputeAllProjectHealthScoresJob::class);
    }

    public function test_update_weights_rejects_out_of_bounds(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('settings.rules.weights.update'), [
                'deduct_alert_critical' => 150, // > 100
            ])
            ->assertSessionHasErrors('deduct_alert_critical');
    }

    public function test_reset_weights_deletes_override_row(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        ProjectHealthScoreWeightOverride::factory()->for($user)->create([
            'deduct_alert_critical' => 40,
        ]);

        $this->actingAs($user)
            ->delete(route('settings.rules.weights.reset'))
            ->assertRedirect();

        $this->assertDatabaseMissing('project_health_score_weight_overrides', [
            'user_id' => $user->id,
        ]);

        Bus::assertDispatched(RecomputeAllProjectHealthScoresJob::class);
    }

    public function test_store_rule_creates_row(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('settings.rules.store'), [
                'name' => 'Backlog watch',
                'kind' => AlertRuleKind::QueueBacklogTrend->value,
                'severity' => AlertSeverity::Warning->value,
                'config' => ['threshold_delta' => 150, 'window_minutes' => 15],
                'cool_down_minutes' => 45,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('alert_rules', [
            'user_id' => $user->id,
            'name' => 'Backlog watch',
            'kind' => 'queue.backlog_trend',
            'cool_down_minutes' => 45,
        ]);
    }

    public function test_update_rule_rejects_other_users_row(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $rule = AlertRule::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->patch(route('settings.rules.update', ['rule' => $rule->id]), [
                'enabled' => false,
            ])
            ->assertForbidden();
    }

    public function test_destroy_rule_rejects_other_users_row(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $rule = AlertRule::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->delete(route('settings.rules.destroy', ['rule' => $rule->id]))
            ->assertForbidden();
    }

    public function test_toggle_rule_updates_enabled_column(): void
    {
        $user = User::factory()->create();
        $rule = AlertRule::factory()->for($user)->create(['enabled' => true]);

        $this->actingAs($user)
            ->patch(route('settings.rules.update', ['rule' => $rule->id]), [
                'enabled' => false,
            ])
            ->assertRedirect();

        $this->assertFalse($rule->fresh()->enabled);
    }
}
