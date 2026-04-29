<?php

namespace App\Http\Controllers;

use App\Domain\GitHub\Actions\PersistGithubConnectionAction;
use App\Domain\GitHub\Services\GitHubOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class GithubConnectionController extends Controller
{
    /**
     * Kick the OAuth handshake. We mint a CSRF-safe `state`, stash it
     * in the session, and redirect the user to GitHub's authorize URL.
     * The matching cleanup happens in `callback()`.
     */
    public function redirect(Request $request, GitHubOAuthService $oauth): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('github_oauth_state', $state);

        return redirect()->away($oauth->redirectUrl($state));
    }

    /**
     * Handle GitHub's redirect back. We verify state, exchange the code,
     * fetch the connected user's profile, and persist (or refresh) the
     * Nexus user's `github_connections` row via the action.
     */
    public function callback(
        Request $request,
        GitHubOAuthService $oauth,
        PersistGithubConnectionAction $persist,
    ): RedirectResponse {
        // Check GitHub's error branch BEFORE consuming the session
        // state — if the user denied or GitHub returned an error, we
        // shouldn't burn the state token (so a retry can still validate).
        if ($request->query('error')) {
            Log::warning('GitHub OAuth callback returned an error', [
                'error' => $request->query('error'),
                'error_description' => $request->query('error_description'),
            ]);

            return redirect()->route('settings.index')->with(
                'error',
                'GitHub connection cancelled or rejected: '.$request->query('error_description', $request->query('error')),
            );
        }

        $expectedState = $request->session()->pull('github_oauth_state');
        $providedState = $request->query('state');

        if (
            $expectedState === null
            || $providedState === null
            || ! hash_equals((string) $expectedState, (string) $providedState)
        ) {
            Log::warning('GitHub OAuth state mismatch', [
                'has_expected' => $expectedState !== null,
                'has_provided' => $providedState !== null,
            ]);

            return redirect()->route('settings.index')->with(
                'error',
                'GitHub connection rejected — state mismatch. Please try again.',
            );
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            Log::warning('GitHub OAuth callback missing code');

            return redirect()->route('settings.index')->with(
                'error',
                'GitHub did not return an authorization code.',
            );
        }

        try {
            $tokenPayload = $oauth->exchangeCode($code);
            $userPayload = $oauth->fetchUser((string) $tokenPayload['access_token']);
        } catch (RuntimeException $e) {
            Log::warning('GitHub OAuth handshake failed', [
                'message' => $e->getMessage(),
                'previous' => $e->getPrevious()?->getMessage(),
            ]);

            return redirect()->route('settings.index')->with(
                'error',
                $e->getMessage(),
            );
        }

        $persist->execute($request->user(), $tokenPayload, $userPayload);

        return redirect()->route('settings.index')->with(
            'status',
            'GitHub connected as @'.($userPayload['login'] ?? 'unknown').'.',
        );
    }

    /** Disconnect — drop the user's `github_connections` row. */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        $user->githubConnection?->delete();

        return redirect()->route('settings.index')->with(
            'status',
            'GitHub disconnected.',
        );
    }
}
