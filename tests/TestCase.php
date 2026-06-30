<?php

namespace Tests;

use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * Spec 040 — verified-and-ready user. Most feature tests need a
     * user that's authenticated AND past the email-verification gate;
     * this trims the scaffolding from `User::factory()->create([
     * 'email_verified_at' => now()])` to one line.
     */
    protected function verifiedUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge(
            ['email_verified_at' => now()],
            $attrs,
        ));
    }

    /**
     * Spec 040 — `[$project, $repository]` tuple where the repo is
     * linked to the project + owned by `$owner`. Used by the project
     * + repo end-to-end test + the webhook + alert test (which needs
     * a repo to map the workflow_run delivery onto).
     *
     * @return array{0: Project, 1: Repository}
     */
    protected function projectWithRepository(User $owner, array $repoAttrs = []): array
    {
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $repository = Repository::factory()->create(array_merge(
            ['project_id' => $project->id],
            $repoAttrs,
        ));

        return [$project, $repository];
    }

    /**
     * Spec 040 — `[headers, raw_body]` tuple for a properly-signed
     * GitHub webhook POST to `/webhooks/github`. Caller posts via:
     *
     *     [$headers, $body] = $this->signedGitHubWebhook('issues', [...]);
     *     $this->call('POST', '/webhooks/github', [], [], [], $headers, $body);
     *
     * Sets the secret on `config('services.github.webhook_secret')`
     * so the controller's verification step finds it.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    protected function signedGitHubWebhook(
        string $event,
        array $payload,
        ?string $delivery = null,
    ): array {
        $secret = 'whsec_e2e_test_secret';
        config(['services.github.webhook_secret' => $secret]);

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret);

        $headers = [
            'HTTP_X-GitHub-Event' => $event,
            'HTTP_X-GitHub-Delivery' => $delivery ?? (string) Str::uuid(),
            'HTTP_X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ];

        return [$headers, $body];
    }
}
