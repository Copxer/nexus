<?php

namespace App\Http\Controllers;

use App\Domain\Observability\Queries\GetSystemHealthQuery;
use App\Models\GithubConnection;
use App\Models\Project;
use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function __invoke(Request $request, GetSystemHealthQuery $systemHealth): Response
    {
        $connection = $request->user()?->githubConnection;

        return Inertia::render('Settings/Index', [
            'github' => $this->serializeConnection($connection, $request),
            // Spec 038 — system health KPIs (queue, webhooks, GitHub
            // rate-limit, agent auth) rendered as a 4-up card above
            // the Integrations block.
            'systemHealth' => $systemHealth->execute(),
        ]);
    }

    /**
     * Trim the connection model to just what the page needs — never
     * leak the (decrypted) token through Inertia props. The model's
     * `$hidden` array protects `toArray()`, but we shape it explicitly
     * here so any future field is opt-in.
     */
    private function serializeConnection(?GithubConnection $connection, Request $request): ?array
    {
        if ($connection === null) {
            return null;
        }

        return [
            'username' => $connection->github_username,
            'connected_at' => $connection->connected_at?->diffForHumans(),
            'expires_at' => $connection->expires_at?->diffForHumans(),
            'is_token_valid' => $connection->isAccessTokenValid(),
            'scopes' => $connection->scopes ?? [],
            'recent_repositories' => $this->recentRepositoriesSummary($request),
        ];
    }

    /**
     * Lightweight summary of the user's repositories for the Settings
     * card: count linked across their projects + most recent sync
     * timestamp. Doesn't load full rows; two scalar queries.
     */
    private function recentRepositoriesSummary(Request $request): array
    {
        $userId = $request->user()?->id;

        if ($userId === null) {
            return ['count' => 0, 'last_synced_at' => null];
        }

        $projectIds = Project::query()
            ->where('owner_user_id', $userId)
            ->pluck('id');

        if ($projectIds->isEmpty()) {
            return ['count' => 0, 'last_synced_at' => null];
        }

        $count = Repository::query()
            ->whereIn('project_id', $projectIds)
            ->count();

        $lastSyncedAt = Repository::query()
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('last_synced_at')
            ->max('last_synced_at');

        return [
            'count' => $count,
            'last_synced_at' => $lastSyncedAt
                ? Carbon::parse($lastSyncedAt)->diffForHumans()
                : null,
        ];
    }
}
