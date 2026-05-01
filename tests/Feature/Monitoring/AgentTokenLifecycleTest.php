<?php

namespace Tests\Feature\Monitoring;

use App\Domain\Docker\Actions\IssueAgentTokenAction;
use App\Domain\Docker\Actions\RotateAgentTokenAction;
use App\Models\AgentToken;
use App\Models\Host;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AgentTokenLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_issue_returns_plaintext_once_and_persists_only_the_hash(): void
    {
        $action = app(IssueAgentTokenAction::class);
        $host = Host::factory()->create();

        $result = $action->execute($host, 'agent v0.1');

        $this->assertSame(40, mb_strlen($result->plaintext));
        $this->assertNotSame($result->plaintext, $result->token->hashed_token);
        $this->assertSame(
            AgentToken::hash($result->plaintext),
            $result->token->fresh()->hashed_token,
        );

        // The DB never sees the plaintext.
        $this->assertSame(
            0,
            AgentToken::query()->where('hashed_token', $result->plaintext)->count(),
        );
    }

    public function test_issue_does_not_log_plaintext(): void
    {
        $host = Host::factory()->create();

        $logSpy = Log::spy();

        app(IssueAgentTokenAction::class)->execute($host);

        // No log call at all is the strongest assertion. If we ever add
        // an audit log call here, narrow the assertion to "the plaintext
        // string isn't a substring of any logged message."
        $logSpy->shouldNotHaveReceived('info');
        $logSpy->shouldNotHaveReceived('debug');
        $logSpy->shouldNotHaveReceived('warning');
        $logSpy->shouldNotHaveReceived('error');
    }

    public function test_rotate_revokes_previous_active_tokens_and_issues_a_fresh_one(): void
    {
        $rotate = app(RotateAgentTokenAction::class);
        $host = Host::factory()->create();
        $existing = AgentToken::factory()->create(['host_id' => $host->id]);

        $result = $rotate->execute($host, 'rotated');

        $existing->refresh();
        $this->assertNotNull($existing->revoked_at);

        $this->assertNull($result->token->revoked_at);
        $this->assertSame('rotated', $result->token->name);
        $this->assertNotSame($existing->id, $result->token->id);
    }

    public function test_store_endpoint_flashes_plaintext_for_one_request(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $host = Host::factory()->create(['project_id' => $project->id]);

        $response = $this->actingAs($user)
            ->post(route('monitoring.hosts.tokens.store', $host), ['name' => 'agent v0.1']);

        $response->assertRedirect(route('monitoring.hosts.show', $host));
        $response->assertSessionHas('agentTokenPlaintext');

        $plaintext = session('agentTokenPlaintext');
        $this->assertIsString($plaintext);
        $this->assertSame(40, mb_strlen($plaintext));

        $token = AgentToken::query()->where('host_id', $host->id)->latest('id')->firstOrFail();
        $this->assertSame(AgentToken::hash($plaintext), $token->hashed_token);
    }

    public function test_rotate_endpoint_invalidates_old_plaintext(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $host = Host::factory()->create(['project_id' => $project->id]);

        // Mint the first token via the controller so we capture the
        // real plaintext exactly once.
        $this->actingAs($user)
            ->post(route('monitoring.hosts.tokens.store', $host));
        $oldPlaintext = session('agentTokenPlaintext');
        $oldToken = AgentToken::query()->where('host_id', $host->id)->latest('id')->firstOrFail();

        // Now rotate and capture the new plaintext.
        $this->actingAs($user)
            ->post(route('monitoring.hosts.tokens.rotate', [$host, $oldToken]))
            ->assertRedirect(route('monitoring.hosts.show', $host));
        $newPlaintext = session('agentTokenPlaintext');

        $this->assertNotSame($oldPlaintext, $newPlaintext);

        // The old plaintext's hash now belongs only to a revoked row.
        $oldHashRow = AgentToken::query()
            ->where('hashed_token', AgentToken::hash($oldPlaintext))
            ->firstOrFail();
        $this->assertNotNull($oldHashRow->revoked_at);

        // The new plaintext's hash maps to an active row.
        $newHashRow = AgentToken::query()
            ->where('hashed_token', AgentToken::hash($newPlaintext))
            ->firstOrFail();
        $this->assertNull($newHashRow->revoked_at);
    }

    public function test_destroy_endpoint_revokes_a_token(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $host = Host::factory()->create(['project_id' => $project->id]);
        $token = AgentToken::factory()->create(['host_id' => $host->id]);

        $this->actingAs($user)
            ->delete(route('monitoring.hosts.tokens.destroy', [$host, $token]))
            ->assertRedirect(route('monitoring.hosts.show', $host));

        $token->refresh();
        $this->assertNotNull($token->revoked_at);
    }

    public function test_token_endpoints_404_on_mismatched_pair(): void
    {
        $user = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $user->id]);
        $hostA = Host::factory()->create(['project_id' => $project->id]);
        $hostB = Host::factory()->create(['project_id' => $project->id]);
        $tokenForB = AgentToken::factory()->create(['host_id' => $hostB->id]);

        $this->actingAs($user)
            ->delete(route('monitoring.hosts.tokens.destroy', [$hostA, $tokenForB]))
            ->assertNotFound();
    }

    public function test_token_endpoints_blocked_for_non_owner(): void
    {
        $owner = $this->verifiedUser();
        $other = $this->verifiedUser();
        $project = Project::factory()->create(['owner_user_id' => $owner->id]);
        $host = Host::factory()->create(['project_id' => $project->id]);

        $this->actingAs($other)
            ->post(route('monitoring.hosts.tokens.store', $host))
            ->assertForbidden();
    }
}
