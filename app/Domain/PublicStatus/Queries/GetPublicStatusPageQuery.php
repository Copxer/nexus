<?php

namespace App\Domain\PublicStatus\Queries;

use App\Domain\Monitoring\Queries\GetWebsitePerformanceSummaryQuery;
use App\Domain\PublicStatus\DataTransferObjects\PublicStatusSnapshot;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\WebsiteStatus;
use App\Models\Alert;
use App\Models\Project;
use App\Models\Website;
use Illuminate\Support\Facades\Cache;

/**
 * Spec 047 — assembles the public status page snapshot. Cached 60s
 * to survive viral shares; invalidated on alert transitions via
 * `InvalidatePublicStatusCacheListener` (spec 032 events).
 *
 * Overall band derivation (matches Statuspage.io shape):
 *   - `major_outage`   → any open critical alert OR any website `down`
 *   - `partial_outage` → any open warning alert OR any website `slow`
 *   - `degraded`       → any open info alert
 *   - `operational`    → nothing open
 */
class GetPublicStatusPageQuery
{
    public const CACHE_TTL_SECONDS = 60;

    public const RECENT_INCIDENTS_LIMIT = 10;

    public static function cacheKey(int $projectId): string
    {
        return "public-status:{$projectId}";
    }

    public function __construct(
        private readonly GetWebsitePerformanceSummaryQuery $websiteSummary,
    ) {}

    public function execute(Project $project): PublicStatusSnapshot
    {
        return Cache::remember(
            self::cacheKey($project->id),
            self::CACHE_TTL_SECONDS,
            fn (): PublicStatusSnapshot => $this->assemble($project),
        );
    }

    private function assemble(Project $project): PublicStatusSnapshot
    {
        $monitors = $this->monitorsFor($project);
        $activeIncidents = $this->activeIncidentsFor($project);
        $recentIncidents = $this->recentIncidentsFor($project);
        [$band, $label] = $this->deriveOverall($project, $activeIncidents);

        return new PublicStatusSnapshot(
            projectId: $project->id,
            projectName: $project->name,
            projectSlug: $project->slug,
            headline: $project->public_status_headline,
            overallBand: $band,
            overallLabel: $label,
            monitors: $monitors,
            activeIncidents: $activeIncidents,
            recentIncidents: $recentIncidents,
            lastUpdatedAt: now()->toIso8601String(),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function monitorsFor(Project $project): array
    {
        return Website::query()
            ->where('project_id', $project->id)
            ->orderBy('name')
            ->get(['id', 'name', 'url', 'status'])
            ->map(function (Website $w): array {
                $summary = $this->websiteSummary->execute($w);

                return [
                    'id' => $w->id,
                    'name' => $w->name,
                    'url' => $w->url,
                    'status' => $w->status?->value,
                    'uptime_24h' => $summary['uptime_24h'],
                    'uptime_7d' => $summary['uptime_7d'],
                    'uptime_30d' => $summary['uptime_30d'],
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activeIncidentsFor(Project $project): array
    {
        return Alert::query()
            ->where('project_id', $project->id)
            ->whereIn('status', [
                AlertStatus::Open->value,
                AlertStatus::Acknowledged->value,
            ])
            ->orderByDesc('triggered_at')
            ->limit(20)
            ->get(['id', 'title', 'severity', 'status', 'triggered_at'])
            ->map(fn (Alert $a): array => [
                'id' => $a->id,
                'title' => $a->title,
                'severity' => $a->severity->value,
                'status' => $a->status->value,
                'triggered_at' => $a->triggered_at?->toIso8601String(),
                'triggered_at_human' => $a->triggered_at?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentIncidentsFor(Project $project): array
    {
        return Alert::query()
            ->where('project_id', $project->id)
            ->where('status', AlertStatus::Resolved->value)
            ->orderByDesc('resolved_at')
            ->limit(self::RECENT_INCIDENTS_LIMIT)
            ->get(['id', 'title', 'severity', 'triggered_at', 'resolved_at'])
            ->map(fn (Alert $a): array => [
                'id' => $a->id,
                'title' => $a->title,
                'severity' => $a->severity->value,
                'triggered_at' => $a->triggered_at?->toIso8601String(),
                'resolved_at' => $a->resolved_at?->toIso8601String(),
                'resolved_at_human' => $a->resolved_at?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $activeIncidents
     * @return array{0: string, 1: string}
     */
    private function deriveOverall(Project $project, array $activeIncidents): array
    {
        $downWebsites = Website::query()
            ->where('project_id', $project->id)
            ->whereIn('status', [
                WebsiteStatus::Down->value,
                WebsiteStatus::Error->value,
            ])
            ->exists();

        $slowWebsites = Website::query()
            ->where('project_id', $project->id)
            ->where('status', WebsiteStatus::Slow->value)
            ->exists();

        $hasCritical = false;
        $hasWarning = false;
        $hasInfo = false;

        foreach ($activeIncidents as $incident) {
            match ($incident['severity']) {
                AlertSeverity::Critical->value => $hasCritical = true,
                AlertSeverity::Warning->value => $hasWarning = true,
                AlertSeverity::Info->value => $hasInfo = true,
                default => null,
            };
        }

        if ($hasCritical || $downWebsites) {
            return ['major_outage', 'Major outage'];
        }

        if ($hasWarning || $slowWebsites) {
            return ['partial_outage', 'Partial outage'];
        }

        if ($hasInfo) {
            return ['degraded', 'Degraded'];
        }

        return ['operational', 'All systems operational'];
    }
}
