<?php

namespace App\Domain\Palette\Queries;

use App\Models\Host;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Collection;

/**
 * Spec 043 — read-side bundle for the pre-loaded palette entities.
 *
 * Ships via the shared Inertia prop on every authenticated page load.
 * Bounded by hard row caps so a heavy user can't blow the payload:
 *   projects   ≤ 50
 *   repos      ≤ 100
 *   hosts      ≤ 50
 *   websites   ≤ 50
 *
 * Async server-side search (`SearchPaletteEntitiesQuery`) fills the gap
 * for alerts + work items where scale is unbounded.
 */
class GetPaletteEntitiesQuery
{
    public const PROJECTS_CAP = 50;

    public const REPOSITORIES_CAP = 100;

    public const HOSTS_CAP = 50;

    public const WEBSITES_CAP = 50;

    /**
     * @return array{
     *   projects: list<array{id:int,label:string,subtitle:?string,keywords:array<int,string>,url:string}>,
     *   repositories: list<array{id:int,label:string,subtitle:?string,keywords:array<int,string>,url:string}>,
     *   hosts: list<array{id:int,label:string,subtitle:?string,keywords:array<int,string>,url:string}>,
     *   websites: list<array{id:int,label:string,subtitle:?string,keywords:array<int,string>,url:string}>,
     * }
     */
    public function execute(User $user): array
    {
        $projectIds = Project::query()
            ->where('owner_user_id', $user->id)
            ->pluck('id');

        return [
            'projects' => $this->serializeProjects($user, $projectIds),
            'repositories' => $this->serializeRepositories($projectIds),
            'hosts' => $this->serializeHosts($projectIds),
            'websites' => $this->serializeWebsites($projectIds),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeProjects(User $user, Collection $projectIds): array
    {
        return Project::query()
            ->where('owner_user_id', $user->id)
            ->orderBy('name')
            ->limit(self::PROJECTS_CAP)
            ->get(['id', 'name', 'slug', 'description'])
            ->map(fn (Project $p): array => [
                'id' => $p->id,
                'label' => $p->name,
                'subtitle' => $p->description
                    ? mb_strimwidth($p->description, 0, 80, '…')
                    : null,
                'keywords' => array_values(array_filter([
                    $p->slug,
                    $p->description ? mb_strimwidth($p->description, 0, 40, '…') : null,
                ])),
                'url' => route('projects.show', $p),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeRepositories(Collection $projectIds): array
    {
        if ($projectIds->isEmpty()) {
            return [];
        }

        return Repository::query()
            ->whereIn('project_id', $projectIds)
            ->orderByDesc('last_pushed_at')
            ->limit(self::REPOSITORIES_CAP)
            ->get(['id', 'full_name', 'language', 'description', 'owner', 'name'])
            ->map(fn (Repository $r): array => [
                'id' => $r->id,
                'label' => $r->full_name,
                'subtitle' => $r->description
                    ? mb_strimwidth($r->description, 0, 80, '…')
                    : ($r->language ?: null),
                'keywords' => array_values(array_filter([
                    $r->owner,
                    $r->name,
                    $r->language,
                ])),
                'url' => route('repositories.show', ['repository' => $r->full_name]),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeHosts(Collection $projectIds): array
    {
        if ($projectIds->isEmpty()) {
            return [];
        }

        return Host::query()
            ->whereIn('project_id', $projectIds)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->limit(self::HOSTS_CAP)
            ->get(['id', 'name', 'slug', 'endpoint_url', 'status'])
            ->map(fn (Host $h): array => [
                'id' => $h->id,
                'label' => $h->name,
                'subtitle' => $h->endpoint_url ?: $h->status?->value,
                'keywords' => array_values(array_filter([
                    $h->slug,
                    $h->endpoint_url,
                ])),
                'url' => route('monitoring.hosts.show', $h),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeWebsites(Collection $projectIds): array
    {
        if ($projectIds->isEmpty()) {
            return [];
        }

        return Website::query()
            ->whereIn('project_id', $projectIds)
            ->orderBy('name')
            ->limit(self::WEBSITES_CAP)
            ->get(['id', 'name', 'url', 'status'])
            ->map(fn (Website $w): array => [
                'id' => $w->id,
                'label' => $w->name,
                'subtitle' => $w->url,
                'keywords' => array_values(array_filter([
                    $w->url,
                    $w->status?->value,
                ])),
                'url' => route('monitoring.websites.show', $w),
            ])
            ->all();
    }
}
