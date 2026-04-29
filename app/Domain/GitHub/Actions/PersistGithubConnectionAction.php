<?php

namespace App\Domain\GitHub\Actions;

use App\Models\GithubConnection;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Persist (or refresh) a Nexus user's GitHub connection. Idempotent on
 * re-connect — the second handshake updates the existing row rather
 * than spawning a duplicate, so disconnect/reconnect cycles stay clean.
 *
 * Tokens land via the model's `encrypted` cast; the action never deals
 * in plaintext beyond passing it through.
 */
class PersistGithubConnectionAction
{
    /**
     * @param  array<string, mixed>  $tokenPayload  GitHub OAuth token response
     * @param  array<string, mixed>  $userPayload  GitHub /user response
     */
    public function execute(
        User $user,
        array $tokenPayload,
        array $userPayload,
    ): GithubConnection {
        return GithubConnection::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'github_user_id' => (string) ($userPayload['id'] ?? ''),
                'github_username' => (string) ($userPayload['login'] ?? ''),
                'access_token' => (string) ($tokenPayload['access_token'] ?? ''),
                'refresh_token' => isset($tokenPayload['refresh_token'])
                    ? (string) $tokenPayload['refresh_token']
                    : null,
                'expires_at' => $this->relativeTimestamp($tokenPayload, 'expires_in'),
                'refresh_token_expires_at' => $this->relativeTimestamp(
                    $tokenPayload,
                    'refresh_token_expires_in',
                ),
                'scopes' => $this->parseScopes($tokenPayload),
                'connected_at' => now(),
            ],
        );
    }

    /**
     * GitHub returns `expires_in` / `refresh_token_expires_in` as a
     * count of seconds — convert to absolute timestamps so the column
     * doesn't drift on re-reads.
     */
    private function relativeTimestamp(array $payload, string $key): ?Carbon
    {
        $seconds = $payload[$key] ?? null;

        if (! is_numeric($seconds)) {
            return null;
        }

        return now()->addSeconds((int) $seconds);
    }

    /**
     * GitHub returns `scope` as a CSV string ("read:user,repo"). Split
     * into an array so the JSON column reads cleanly.
     *
     * @return array<int, string>|null
     */
    private function parseScopes(array $payload): ?array
    {
        $raw = $payload['scope'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
