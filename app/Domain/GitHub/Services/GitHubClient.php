<?php

namespace App\Domain\GitHub\Services;

use App\Domain\GitHub\Exceptions\GitHubApiException;
use App\Models\GithubConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Authenticated REST wrapper around GitHub's API. Each instance is
 * scoped to a single `GithubConnection` (per-user OAuth from spec 013)
 * — explicit construction makes the dependency obvious and test
 * doubles trivial.
 *
 * All HTTP traffic flows through Laravel's `Http` facade so tests
 * mock with `Http::fake()`. Headers are pinned to the `2022-11-28`
 * GitHub API version because we depend on specific response shapes
 * (e.g. `default_branch`, `stargazers_count`) that older versions
 * could plausibly drop.
 */
class GitHubClient
{
    private const BASE_URL = 'https://api.github.com';

    /** Single-page cap; we sort by pushed-desc so freshest 100 wins. */
    private const DEFAULT_PER_PAGE = 100;

    public function __construct(private readonly GithubConnection $connection) {}

    /**
     * GET /user/repos — every repo the user can see (owner +
     * collaborator + organization member where granted). Capped at
     * 100 entries (sort=pushed). Multi-page support lands later if
     * power users hit the cap.
     */
    public function listRepositories(int $perPage = self::DEFAULT_PER_PAGE): array
    {
        try {
            $response = $this->request()->get(self::BASE_URL.'/user/repos', [
                'sort' => 'pushed',
                'direction' => 'desc',
                'per_page' => max(1, min($perPage, 100)),
                'page' => 1,
            ]);

            if ($response->failed()) {
                throw GitHubApiException::fromResponse(
                    $response,
                    'GitHub /user/repos failed',
                );
            }

            return (array) $response->json();
        } catch (RequestException $e) {
            throw GitHubApiException::fromTransport($e, 'GitHub /user/repos failed');
        }
    }

    /**
     * GET /repos/{owner}/{name} — single repo's metadata. Used by
     * `SyncGitHubRepositoryJob` to refresh local rows. Throws on 404.
     */
    public function fetchRepository(string $fullName): array
    {
        try {
            $response = $this->request()->get(self::BASE_URL."/repos/{$fullName}");

            if ($response->failed()) {
                throw GitHubApiException::fromResponse(
                    $response,
                    "GitHub /repos/{$fullName} failed",
                );
            }

            return (array) $response->json();
        } catch (RequestException $e) {
            throw GitHubApiException::fromTransport(
                $e,
                "GitHub /repos/{$fullName} failed",
            );
        }
    }

    /**
     * GET /repos/{owner}/{name}/issues — issues + (annoyingly) PRs.
     * The caller filters PRs out via `NormalizeGitHubIssueAction`.
     *
     * `state=all` because we want closed issues mirrored locally too.
     * `since` is sent on follow-up syncs so we only pull what's been
     * touched since the last successful sync.
     */
    public function listIssues(
        string $fullName,
        ?Carbon $since = null,
        int $perPage = self::DEFAULT_PER_PAGE,
    ): array {
        $query = [
            'state' => 'all',
            'sort' => 'updated',
            'direction' => 'desc',
            'per_page' => max(1, min($perPage, 100)),
            'page' => 1,
        ];

        if ($since !== null) {
            $query['since'] = $since->toIso8601String();
        }

        try {
            $response = $this->request()
                ->get(self::BASE_URL."/repos/{$fullName}/issues", $query);

            if ($response->failed()) {
                throw GitHubApiException::fromResponse(
                    $response,
                    "GitHub /repos/{$fullName}/issues failed",
                );
            }

            return (array) $response->json();
        } catch (RequestException $e) {
            throw GitHubApiException::fromTransport(
                $e,
                "GitHub /repos/{$fullName}/issues failed",
            );
        }
    }

    /**
     * GET /repos/{owner}/{name}/pulls — pull requests only (separate
     * endpoint from `/issues`, which fans in PRs as a side-effect).
     *
     * `state=all` so closed + merged PRs land locally too. GitHub's
     * `/pulls` endpoint does NOT support `?since=`, so unlike
     * `listIssues` we always full-fetch the most-recently-updated 100.
     */
    public function listPullRequests(
        string $fullName,
        int $perPage = self::DEFAULT_PER_PAGE,
    ): array {
        try {
            $response = $this->request()
                ->get(self::BASE_URL."/repos/{$fullName}/pulls", [
                    'state' => 'all',
                    'sort' => 'updated',
                    'direction' => 'desc',
                    'per_page' => max(1, min($perPage, 100)),
                    'page' => 1,
                ]);

            if ($response->failed()) {
                throw GitHubApiException::fromResponse(
                    $response,
                    "GitHub /repos/{$fullName}/pulls failed",
                );
            }

            return (array) $response->json();
        } catch (RequestException $e) {
            throw GitHubApiException::fromTransport(
                $e,
                "GitHub /repos/{$fullName}/pulls failed",
            );
        }
    }

    /**
     * Shared HTTP client config — auth + pinned API version + UA.
     * Note: we don't call `->throw()` on the PendingRequest; the
     * callers check `$response->failed()` and route the failure into
     * `GitHubApiException` so the typed exception is the only thing
     * the rest of the code sees.
     */
    private function request(): PendingRequest
    {
        return Http::withToken($this->connection->access_token)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'Nexus-Control-Center',
            ]);
    }
}
