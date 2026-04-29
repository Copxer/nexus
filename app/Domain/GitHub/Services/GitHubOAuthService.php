<?php

namespace App\Domain\GitHub\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to GitHub's OAuth + user endpoints. Three responsibilities:
 *
 *  - `redirectUrl()` — build the `https://github.com/login/oauth/authorize`
 *    URL that the user follows to grant Nexus access.
 *  - `exchangeCode()` — trade the post-redirect `code` for an access
 *    token (POST `https://github.com/login/oauth/access_token`).
 *  - `fetchUser()` — read the connected GitHub user's id + login from
 *    `GET https://api.github.com/user`. Used to label the connection
 *    in the UI ("@octocat") and persist the GitHub user id.
 *
 * All HTTP traffic flows through Laravel's `Http` facade so tests can
 * mock it via `Http::fake()`. No real GitHub credentials are required
 * in CI.
 */
class GitHubOAuthService
{
    private const AUTHORIZE_URL = 'https://github.com/login/oauth/authorize';

    private const TOKEN_URL = 'https://github.com/login/oauth/access_token';

    private const USER_URL = 'https://api.github.com/user';

    /**
     * Build the redirect URL the user follows to grant Nexus access.
     * `$state` is a CSRF guard; the controller stashes it in the session
     * and verifies it on the callback.
     */
    public function redirectUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'scope' => implode(' ', config('services.github.scopes', [])),
            'state' => $state,
        ]);
    }

    /**
     * Trade the post-redirect `code` for an access token. Returns the
     * decoded GitHub response — at minimum:
     *   - access_token (string)
     *   - token_type   (string)
     *   - scope        (CSV string)
     *   - refresh_token (string, present for GitHub Apps)
     *   - expires_in   (int seconds, present for GitHub Apps)
     *   - refresh_token_expires_in (int seconds, present for GitHub Apps)
     *
     * Throws `RuntimeException` if GitHub returns an error payload.
     */
    public function exchangeCode(string $code): array
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->post(self::TOKEN_URL, [
                    'client_id' => $this->clientId(),
                    'client_secret' => $this->clientSecret(),
                    'code' => $code,
                    'redirect_uri' => $this->redirectUri(),
                ])
                ->throw()
                ->json();
        } catch (RequestException $e) {
            throw new RuntimeException(
                'GitHub token exchange failed: '.$e->getMessage(),
                previous: $e,
            );
        }

        if (! is_array($response) || isset($response['error'])) {
            $reason = is_array($response) ? ($response['error_description'] ?? $response['error'] ?? 'unknown') : 'invalid response';
            throw new RuntimeException("GitHub token exchange rejected: {$reason}");
        }

        return $response;
    }

    /**
     * Read the connected GitHub user's profile. Returns the decoded
     * GitHub user object (at least `id` + `login`).
     */
    public function fetchUser(string $accessToken): array
    {
        try {
            return Http::withToken($accessToken)
                ->acceptJson()
                ->withHeaders(['User-Agent' => 'Nexus-Control-Center'])
                ->get(self::USER_URL)
                ->throw()
                ->json();
        } catch (RequestException $e) {
            throw new RuntimeException(
                'GitHub /user fetch failed: '.$e->getMessage(),
                previous: $e,
            );
        }
    }

    private function clientId(): string
    {
        return (string) config('services.github.client_id', '');
    }

    private function clientSecret(): string
    {
        return (string) config('services.github.client_secret', '');
    }

    private function redirectUri(): string
    {
        return (string) config('services.github.redirect', '');
    }
}
