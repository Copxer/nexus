<?php

namespace Tests\Feature\Alerts;

use App\Domain\Alerts\Actions\TriggerAlertAction;
use App\Domain\Alerts\Jobs\EvaluateAlertRulesJob;
use App\Enums\AlertRuleKind;
use App\Enums\AlertSeverity;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Spec 046 — scheduled evaluator loop. Delegates each enabled
 * `AlertRule` to its kind's evaluator; fires `TriggerAlertAction`
 * on truth; respects cool-down; skips disabled rows.
 */
class EvaluateAlertRulesJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_rules_are_skipped(): void
    {
        $user = User::factory()->create();
        AlertRule::factory()->for($user)->disabled()->create();

        app(EvaluateAlertRulesJob::class)->handle(app(TriggerAlertAction::class));

        $this->assertSame(0, Alert::query()->count());
    }

    public function test_evaluated_at_updates_on_every_tick(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        // Baseline: rule with a big threshold that won't trip.
        $rule = AlertRule::factory()->for($user)->create([
            'kind' => AlertRuleKind::QueueBacklogTrend->value,
            'config' => ['threshold_delta' => 99999],
        ]);

        app(EvaluateAlertRulesJob::class)->handle(app(TriggerAlertAction::class));

        $this->assertNotNull($rule->fresh()->last_evaluated_at);
        // Not triggered → last_triggered_at stays null.
        $this->assertNull($rule->fresh()->last_triggered_at);
    }

    public function test_evaluation_true_fires_a_system_alert_and_marks_triggered_at(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        // Zero threshold → any backlog (even 0) trips the rule.
        $rule = AlertRule::factory()->for($user)->create([
            'kind' => AlertRuleKind::QueueBacklogTrend->value,
            'severity' => AlertSeverity::Critical->value,
            'config' => ['threshold_delta' => 0, 'window_minutes' => 15],
            'last_triggered_at' => null,
        ]);

        app(EvaluateAlertRulesJob::class)->handle(app(TriggerAlertAction::class));

        $this->assertNotNull($rule->fresh()->last_triggered_at);

        $alert = Alert::query()
            ->where('source', 'system')
            ->where('type', 'rule.queue.backlog_trend')
            ->where('source_id', $rule->id)
            ->first();

        $this->assertNotNull($alert);
        $this->assertSame(AlertSeverity::Critical->value, $alert->severity->value);
    }

    public function test_rule_in_cool_down_does_not_re_trigger(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        // Zero threshold + already-triggered → still shouldn't fire again.
        $rule = AlertRule::factory()->for($user)->create([
            'kind' => AlertRuleKind::QueueBacklogTrend->value,
            'config' => ['threshold_delta' => 0],
            'last_triggered_at' => now()->subMinutes(5),
            'cool_down_minutes' => 30,
        ]);

        app(EvaluateAlertRulesJob::class)->handle(app(TriggerAlertAction::class));

        // No fresh system-alert row.
        $this->assertSame(
            0,
            Alert::query()->where('source_id', $rule->id)->count(),
        );
    }
}
