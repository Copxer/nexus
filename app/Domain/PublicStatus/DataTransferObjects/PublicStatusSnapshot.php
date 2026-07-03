<?php

namespace App\Domain\PublicStatus\DataTransferObjects;

/**
 * Spec 047 — assembled public status snapshot. Shape is deliberately
 * flat so the Vue page can render it without further shaping.
 *
 * `overallBand` — one of `operational` | `degraded` | `partial_outage`
 * | `major_outage`.
 */
final class PublicStatusSnapshot
{
    /**
     * @param  array<int, array<string, mixed>>  $monitors
     * @param  array<int, array<string, mixed>>  $activeIncidents
     * @param  array<int, array<string, mixed>>  $recentIncidents
     */
    public function __construct(
        public readonly int $projectId,
        public readonly string $projectName,
        public readonly string $projectSlug,
        public readonly ?string $headline,
        public readonly string $overallBand,
        public readonly string $overallLabel,
        public readonly array $monitors,
        public readonly array $activeIncidents,
        public readonly array $recentIncidents,
        public readonly string $lastUpdatedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'project_name' => $this->projectName,
            'project_slug' => $this->projectSlug,
            'headline' => $this->headline,
            'overall_band' => $this->overallBand,
            'overall_label' => $this->overallLabel,
            'monitors' => $this->monitors,
            'active_incidents' => $this->activeIncidents,
            'recent_incidents' => $this->recentIncidents,
            'last_updated_at' => $this->lastUpdatedAt,
        ];
    }
}
