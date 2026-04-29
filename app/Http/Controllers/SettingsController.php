<?php

namespace App\Http\Controllers;

use App\Models\GithubConnection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $connection = $request->user()?->githubConnection;

        return Inertia::render('Settings/Index', [
            'github' => $this->serializeConnection($connection),
        ]);
    }

    /**
     * Trim the connection model to just what the page needs — never
     * leak the (decrypted) token through Inertia props. The model's
     * `$hidden` array protects `toArray()`, but we shape it explicitly
     * here so any future field is opt-in.
     */
    private function serializeConnection(?GithubConnection $connection): ?array
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
        ];
    }
}
