<?php

namespace Tests\Feature\Agent;

use App\Domain\Docker\Actions\IssueAgentTokenAction;
use App\Models\AgentToken;
use App\Models\Host;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticateAgentMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Minimal valid payload — the middleware tests only auth, but the
     * Form Request would otherwise short-circuit a 200 path with a 422.
     * `host.metrics` needs at least one key for `required` to pass.
     */
    private function payload(): array
    {
        return [
            'recorded_at' => now()->toIso8601String(),
            'host' => [
                'metrics' => [
                    'cpu_percent' => null,
                ],
            ],
        ];
    }

    public function test_returns_204_for_valid_bearer(): void
    {
        $host = Host::factory()->create();
        $result = app(IssueAgentTokenAction::class)->execute($host);

        $this->postJson(
            route('agent.telemetry'),
            $this->payload(),
            ['Authorization' => 'Bearer '.$result->plaintext],
        )->assertNoContent();

        $this->assertNotNull($result->token->fresh()->last_used_at);
    }

    public function test_returns_401_with_no_authorization_header(): void
    {
        $this->postJson(route('agent.telemetry'), $this->payload())
            ->assertStatus(401);
    }

    public function test_returns_401_with_malformed_bearer(): void
    {
        $this->postJson(
            route('agent.telemetry'),
            $this->payload(),
            ['Authorization' => 'NotBearer xyz'],
        )->assertStatus(401);
    }

    public function test_returns_401_for_unknown_token(): void
    {
        $this->postJson(
            route('agent.telemetry'),
            $this->payload(),
            ['Authorization' => 'Bearer not-a-real-token'],
        )->assertStatus(401);
    }

    public function test_returns_401_for_revoked_token(): void
    {
        $host = Host::factory()->create();
        $result = app(IssueAgentTokenAction::class)->execute($host);
        $result->token->forceFill(['revoked_at' => now()])->save();

        $this->postJson(
            route('agent.telemetry'),
            $this->payload(),
            ['Authorization' => 'Bearer '.$result->plaintext],
        )->assertStatus(401);
    }

    public function test_returns_401_when_host_is_archived(): void
    {
        $host = Host::factory()->create();
        $result = app(IssueAgentTokenAction::class)->execute($host);

        // Archive directly — bypasses ArchiveHostAction's token revoke
        // step on purpose, to prove the middleware re-checks the host
        // state rather than relying on revocation alone.
        $host->forceFill(['archived_at' => now()])->save();

        $this->postJson(
            route('agent.telemetry'),
            $this->payload(),
            ['Authorization' => 'Bearer '.$result->plaintext],
        )->assertStatus(401);
    }

    public function test_does_not_stamp_last_used_on_failure(): void
    {
        $host = Host::factory()->create();
        $token = AgentToken::factory()->revoked()->create(['host_id' => $host->id]);

        $this->postJson(
            route('agent.telemetry'),
            $this->payload(),
            // We don't have the plaintext for a factory-revoked token
            // (the factory hashes a random plaintext we discard), so
            // this also doubles as the unknown-token case for a
            // different reason.
            ['Authorization' => 'Bearer pretend-this-matched'],
        )->assertStatus(401);

        $this->assertNull($token->fresh()->last_used_at);
    }
}
