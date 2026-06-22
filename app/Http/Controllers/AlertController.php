<?php

namespace App\Http\Controllers;

use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/alerts` — Phase 7 alerts index (spec 031).
 *
 * Filters live in the query string so a filtered view is shareable and
 * survives reload — same pattern as the Deployments timeline. Default
 * landing is `status: open` so the page is actionable on arrival;
 * resolved / muted rows are reachable via the dropdown.
 *
 * Visible scope: phase-1 single-owner — the user's own projects. Sort
 * happens in PHP after the fetch (`severity priority` is awkward in
 * SQL cross-DB and phase-1 alert counts are well below any size where
 * server-side sort would matter).
 */
class AlertController extends Controller
{
    /**
     * Status priority ladder for the column-sort fallback used in 031.
     * Lower = sorted earlier. Open + acknowledged sit at the top of any
     * mixed-status list; resolved / muted fall to the bottom.
     */
    private const STATUS_PRIORITY = [
        'open' => 0,
        'acknowledged' => 1,
        'muted' => 2,
        'resolved' => 3,
    ];

    /** Severity priority ladder for the primary sort. */
    private const SEVERITY_PRIORITY = [
        'critical' => 0,
        'warning' => 1,
        'info' => 2,
    ];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Alert::class);

        $user = $request->user();

        $severityValues = array_map(fn (AlertSeverity $s) => $s->value, AlertSeverity::cases());
        $sourceValues = array_map(fn (AlertSource $s) => $s->value, AlertSource::cases());
        $statusValues = array_map(fn (AlertStatus $s) => $s->value, AlertStatus::cases());

        // `status: 'all'` is the explicit "show every status" sentinel.
        // It survives a URL round-trip so the Vue dropdown can bind a
        // non-null value to "Any status". Missing status → default to
        // open (lands on the actionable set).
        $validated = $request->validate([
            'severity' => 'sometimes|nullable|in:'.implode(',', $severityValues),
            'source' => 'sometimes|nullable|in:'.implode(',', $sourceValues),
            'status' => 'sometimes|nullable|in:'.implode(',', [...$statusValues, 'all']),
            'project_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('projects', 'id')->where('owner_user_id', $user->id),
            ],
        ]);

        $rawStatus = $validated['status'] ?? null;
        $status = $rawStatus === 'all'
            ? null
            : ($rawStatus ?? AlertStatus::Open->value);

        $severity = $validated['severity'] ?? null;
        $source = $validated['source'] ?? null;
        $projectId = $validated['project_id'] ?? null;

        $alerts = Alert::query()
            ->with('project:id,slug,name,color,icon,owner_user_id')
            // Spec 038 — `AlertSource::System` alerts have a null
            // project_id (queue / GitHub-rate / webhook / agent-auth
            // signals aren't project-scoped). Without this branch
            // the `whereHas('project')` filter excludes them and the
            // Settings system-health card's "View details" link
            // would land on an empty list.
            ->where(function ($outer) use ($user): void {
                $outer
                    ->whereHas('project', fn ($q) => $q->where('owner_user_id', $user->id))
                    ->orWhere('source', AlertSource::System->value);
            })
            ->when($severity, fn ($q) => $q->where('severity', $severity))
            ->when($source, fn ($q) => $q->where('source', $source))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->get()
            ->sortBy(fn (Alert $alert): array => [
                self::STATUS_PRIORITY[$alert->status?->value] ?? 9,
                self::SEVERITY_PRIORITY[$alert->severity?->value] ?? 9,
                -($alert->triggered_at?->timestamp ?? 0),
                -$alert->id,
            ])
            ->values()
            ->map(fn (Alert $alert) => $this->transform($alert))
            ->all();

        return Inertia::render('Alerts/Index', [
            'alerts' => $alerts,
            'filters' => [
                'severity' => $severity,
                'source' => $source,
                // Round-trip the sentinel so the Vue dropdown can
                // re-select "Any status" on reload.
                'status' => $rawStatus === 'all' ? 'all' : $status,
                'project_id' => $projectId,
            ],
            'filterOptions' => [
                'severities' => $severityValues,
                'sources' => $sourceValues,
                'statuses' => $statusValues,
                'projects' => Project::query()
                    ->where('owner_user_id', $user->id)
                    ->orderBy('name')
                    ->get(['id', 'name', 'color'])
                    ->map(fn (Project $project) => [
                        'id' => $project->id,
                        'name' => $project->name,
                        'color' => $project->color,
                    ])
                    ->all(),
            ],
        ]);
    }

    /**
     * Wire-shape for a single Alert row. The `affected_entity_url` and
     * the three `can_*` flags are server-resolved so the Vue layer
     * stays dumb (and we don't repeat the source→route map in TS).
     *
     * @return array<string, mixed>
     */
    private function transform(Alert $alert): array
    {
        return [
            'id' => $alert->id,
            'source' => $alert->source?->value,
            'source_id' => $alert->source_id,
            'type' => $alert->type,
            'severity' => $alert->severity?->value,
            'severity_tone' => $alert->severity?->badgeTone(),
            'status' => $alert->status?->value,
            'title' => $alert->title,
            'description' => $alert->description,
            'triggered_at' => $alert->triggered_at?->diffForHumans(),
            'triggered_at_iso' => $alert->triggered_at?->toIso8601String(),
            'acknowledged_at' => $alert->acknowledged_at?->diffForHumans(),
            'resolved_at' => $alert->resolved_at?->diffForHumans(),
            'last_seen_at' => $alert->last_seen_at?->diffForHumans(),
            'metadata' => $alert->metadata,
            'project' => $alert->project ? [
                'id' => $alert->project->id,
                'slug' => $alert->project->slug,
                'name' => $alert->project->name,
                'color' => $alert->project->color,
                'icon' => $alert->project->icon,
            ] : null,
            'affected_entity_url' => $this->affectedEntityUrl($alert),
            // Action visibility: only the verbs that are valid for the
            // current status. The Vue layer decides whether to render
            // the button; the controllers enforce again at submit time.
            'can_acknowledge' => $alert->status === AlertStatus::Open,
            'can_resolve' => $alert->status === AlertStatus::Open
                || $alert->status === AlertStatus::Acknowledged,
            'can_mute' => $alert->status !== AlertStatus::Muted
                && $alert->status !== AlertStatus::Resolved,
        ];
    }

    /**
     * Server-resolved drill-down URL keyed off the alert's source.
     * Phase 7 maps three sources; new sources fall through to null
     * (the UI renders the icon disabled).
     */
    private function affectedEntityUrl(Alert $alert): ?string
    {
        if ($alert->source_id === null) {
            return null;
        }

        return match ($alert->source) {
            AlertSource::Website => route('monitoring.websites.show', $alert->source_id),
            AlertSource::Docker => route('monitoring.hosts.show', $alert->source_id),
            AlertSource::Deployment => $alert->metadata['html_url'] ?? null,
            default => null,
        };
    }
}
