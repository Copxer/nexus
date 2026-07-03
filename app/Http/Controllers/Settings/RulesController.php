<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Alerts\Support\AlertRuleTemplate;
use App\Domain\Analytics\Actions\ComputeProjectHealthScoreAction;
use App\Domain\Analytics\DataTransferObjects\HealthScoreWeights;
use App\Domain\Analytics\Jobs\RecomputeAllProjectHealthScoresJob;
use App\Enums\AlertRuleKind;
use App\Enums\AlertSeverity;
use App\Http\Controllers\Controller;
use App\Models\AlertRule;
use App\Models\ProjectHealthScoreWeightOverride;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Spec 046 — `/settings/rules` unified surface for two related knobs:
 *
 *  - Weights tab: per-user overrides for the 8 `DEDUCT_*` constants
 *    in `ComputeProjectHealthScoreAction`.
 *  - Rules tab:   user-defined metric alert rules that fire via the
 *    scheduled `EvaluateAlertRulesJob`.
 *
 * Both slices share one page + one JSON payload. Every mutation is
 * per-user scoped.
 */
class RulesController extends Controller
{
    private const WEIGHT_FIELDS = [
        'deduct_alert_critical',
        'deduct_alert_warning',
        'deduct_deploy_failed',
        'deduct_website_slow',
        'deduct_website_down',
        'deduct_host_offline',
        'deduct_container_unhealthy',
        'deduct_gh_sync_failed',
    ];

    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $override = ProjectHealthScoreWeightOverride::query()
            ->where('user_id', $userId)
            ->first();

        $weights = HealthScoreWeights::forUser($request->user());

        $rules = AlertRule::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (AlertRule $r): array => [
                'id' => $r->id,
                'name' => $r->name,
                'kind' => $r->kind->value,
                'kind_label' => $r->kind->label(),
                'severity' => $r->severity->value,
                'config' => $r->config ?? [],
                'enabled' => $r->enabled,
                'cool_down_minutes' => $r->cool_down_minutes,
                'last_evaluated_at' => $r->last_evaluated_at?->diffForHumans(),
                'last_triggered_at' => $r->last_triggered_at?->diffForHumans(),
            ]);

        return Inertia::render('Settings/Rules/Index', [
            'weights' => [
                'defaults' => $this->defaultsPayload(),
                'overrides' => $override ? $this->overridesPayload($override) : null,
                'resolved' => [
                    'alert_critical' => $weights->alertCritical(),
                    'alert_warning' => $weights->alertWarning(),
                    'deploy_failed' => $weights->deployFailed(),
                    'website_slow' => $weights->websiteSlow(),
                    'website_down' => $weights->websiteDown(),
                    'host_offline' => $weights->hostOffline(),
                    'container_unhealthy' => $weights->containerUnhealthy(),
                    'gh_sync_failed' => $weights->ghSyncFailed(),
                ],
            ],
            'rules' => $rules,
            'options' => [
                'kinds' => array_map(
                    fn (AlertRuleKind $k): array => [
                        'value' => $k->value,
                        'label' => $k->label(),
                    ],
                    AlertRuleKind::cases(),
                ),
                'severities' => array_map(
                    fn (AlertSeverity $s): string => $s->value,
                    AlertSeverity::cases(),
                ),
                'templates' => AlertRuleTemplate::all(),
            ],
        ]);
    }

    public function updateWeights(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (self::WEIGHT_FIELDS as $field) {
            $rules[$field] = 'sometimes|nullable|integer|min:0|max:100';
        }
        $validated = $request->validate($rules);

        $attrs = ['user_id' => $request->user()->id];
        foreach (self::WEIGHT_FIELDS as $field) {
            $attrs[$field] = array_key_exists($field, $validated)
                ? $validated[$field]
                : null;
        }

        ProjectHealthScoreWeightOverride::query()->updateOrCreate(
            ['user_id' => $attrs['user_id']],
            $attrs,
        );

        // Fire-and-forget: reassert the score across every project this
        // user owns so the new formula lands without waiting for the
        // scheduled sweep.
        RecomputeAllProjectHealthScoresJob::dispatch();

        return back()->with('status', 'Health-score weights updated.');
    }

    public function resetWeights(Request $request): RedirectResponse
    {
        ProjectHealthScoreWeightOverride::query()
            ->where('user_id', $request->user()->id)
            ->delete();

        RecomputeAllProjectHealthScoresJob::dispatch();

        return back()->with('status', 'Health-score weights reset to defaults.');
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'kind' => 'required|in:'.implode(',', array_column(AlertRuleKind::cases(), 'value')),
            'severity' => 'required|in:'.implode(',', array_column(AlertSeverity::cases(), 'value')),
            'config' => 'sometimes|nullable|array',
            'cool_down_minutes' => 'sometimes|integer|min:1|max:1440',
        ]);

        AlertRule::query()->create([
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'kind' => $validated['kind'],
            'severity' => $validated['severity'],
            'config' => $validated['config'] ?? [],
            'enabled' => true,
            'cool_down_minutes' => $validated['cool_down_minutes'] ?? 30,
        ]);

        return back()->with('status', 'Rule added.');
    }

    public function updateRule(Request $request, AlertRule $rule): RedirectResponse
    {
        if ($rule->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'severity' => 'sometimes|required|in:'.implode(',', array_column(AlertSeverity::cases(), 'value')),
            'config' => 'sometimes|nullable|array',
            'enabled' => 'sometimes|boolean',
            'cool_down_minutes' => 'sometimes|integer|min:1|max:1440',
        ]);

        $rule->fill($validated)->save();

        return back()->with('status', 'Rule updated.');
    }

    public function destroyRule(Request $request, AlertRule $rule): RedirectResponse
    {
        if ($rule->user_id !== $request->user()->id) {
            abort(403);
        }

        $rule->delete();

        return back()->with('status', 'Rule deleted.');
    }

    private function defaultsPayload(): array
    {
        return [
            'alert_critical' => ComputeProjectHealthScoreAction::DEDUCT_ALERT_CRITICAL,
            'alert_warning' => ComputeProjectHealthScoreAction::DEDUCT_ALERT_WARNING,
            'deploy_failed' => ComputeProjectHealthScoreAction::DEDUCT_DEPLOY_FAILED,
            'website_slow' => ComputeProjectHealthScoreAction::DEDUCT_WEBSITE_SLOW,
            'website_down' => ComputeProjectHealthScoreAction::DEDUCT_WEBSITE_DOWN,
            'host_offline' => ComputeProjectHealthScoreAction::DEDUCT_HOST_OFFLINE,
            'container_unhealthy' => ComputeProjectHealthScoreAction::DEDUCT_CONTAINER_UNHEALTHY,
            'gh_sync_failed' => ComputeProjectHealthScoreAction::DEDUCT_GH_SYNC_FAILED,
        ];
    }

    private function overridesPayload(ProjectHealthScoreWeightOverride $override): array
    {
        return [
            'alert_critical' => $override->deduct_alert_critical,
            'alert_warning' => $override->deduct_alert_warning,
            'deploy_failed' => $override->deduct_deploy_failed,
            'website_slow' => $override->deduct_website_slow,
            'website_down' => $override->deduct_website_down,
            'host_offline' => $override->deduct_host_offline,
            'container_unhealthy' => $override->deduct_container_unhealthy,
            'gh_sync_failed' => $override->deduct_gh_sync_failed,
        ];
    }
}
