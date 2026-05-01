<?php

namespace App\Domain\Docker\Actions;

use App\Enums\HostConnectionType;
use App\Enums\HostStatus;
use App\Models\Host;
use App\Models\Project;
use Illuminate\Support\Str;

class CreateHostAction
{
    /**
     * @param  array{
     *     name: string,
     *     provider?: ?string,
     *     endpoint_url?: ?string,
     *     connection_type?: HostConnectionType|string|null,
     *     os?: ?string,
     *     docker_version?: ?string,
     *     cpu_count?: ?int,
     *     memory_total_mb?: ?int,
     *     disk_total_gb?: ?int,
     * }  $attributes
     */
    public function execute(Project $project, array $attributes): Host
    {
        $name = $attributes['name'];
        $connectionType = $attributes['connection_type'] ?? HostConnectionType::Agent;

        return Host::query()->create([
            'project_id' => $project->id,
            'name' => $name,
            'slug' => $this->uniqueSlugForProject($project, $name),
            'provider' => $attributes['provider'] ?? null,
            'endpoint_url' => $attributes['endpoint_url'] ?? null,
            'connection_type' => $connectionType instanceof HostConnectionType
                ? $connectionType->value
                : (string) $connectionType,
            'status' => HostStatus::Pending->value,
            'os' => $attributes['os'] ?? null,
            'docker_version' => $attributes['docker_version'] ?? null,
            'cpu_count' => $attributes['cpu_count'] ?? null,
            'memory_total_mb' => $attributes['memory_total_mb'] ?? null,
            'disk_total_gb' => $attributes['disk_total_gb'] ?? null,
        ]);
    }

    /**
     * Slugs are unique per project. The DB unique index is the final
     * gate; this loop just keeps the user-visible URL reasonable when
     * two hosts share a name.
     */
    private function uniqueSlugForProject(Project $project, string $name): string
    {
        $base = Str::slug($name) ?: 'host';
        $candidate = $base;
        $i = 2;

        while (Host::query()->where('project_id', $project->id)->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$i++;
        }

        return $candidate;
    }
}
