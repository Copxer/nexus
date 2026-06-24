<?php

namespace Tests\Feature\Security;

use App\Domain\Docker\Actions\IssueAgentTokenAction;
use App\Models\AgentToken;
use App\Models\Host;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Spec 039 — pin agent-token handling: plaintext never persisted,
 * only the sha256 hash. The plaintext is shown to the user once at
 * issuance and discarded. `$hidden` keeps the hash out of every
 * default serialization (Inertia, JSON, logs).
 */
class AgentTokenSecretTest extends TestCase
{
    use RefreshDatabase;

    public function test_plaintext_token_is_never_persisted_at_rest(): void
    {
        $host = Host::factory()->create();
        $result = app(IssueAgentTokenAction::class)->execute($host);
        $plaintext = $result->plaintext;

        $this->assertNotEmpty($plaintext);

        $raw = DB::table('agent_tokens')->where('id', $result->token->id)->first();

        $this->assertSame(
            AgentToken::hash($plaintext),
            $raw->hashed_token,
            'Stored column must hold the sha256 of the plaintext, nothing else.',
        );
        $this->assertNotSame($plaintext, $raw->hashed_token);
    }

    public function test_hashed_token_is_hidden_from_array_serialization(): void
    {
        $host = Host::factory()->create();
        $token = app(IssueAgentTokenAction::class)->execute($host)->token;

        $serialized = $token->fresh()->toArray();

        $this->assertArrayNotHasKey('hashed_token', $serialized);
    }

    public function test_hash_helper_is_deterministic_and_not_reversible(): void
    {
        $a = AgentToken::hash('candidate-plaintext-A');
        $b = AgentToken::hash('candidate-plaintext-A');
        $c = AgentToken::hash('candidate-plaintext-B');

        $this->assertSame($a, $b, 'Hashing the same plaintext twice yields the same digest.');
        $this->assertNotSame($a, $c, 'Different plaintexts must yield different digests.');
        // sha256 is 64 hex chars.
        $this->assertSame(64, strlen($a));
    }

    public function test_revoked_token_rejected_by_agent_telemetry_endpoint(): void
    {
        $host = Host::factory()->create();
        $result = app(IssueAgentTokenAction::class)->execute($host);
        $result->token->forceFill(['revoked_at' => now()])->save();

        $this->postJson(
            route('agent.telemetry'),
            [
                'recorded_at' => now()->toIso8601String(),
                'host' => ['metrics' => ['cpu_percent' => null]],
            ],
            ['Authorization' => 'Bearer '.$result->plaintext],
        )->assertStatus(401);
    }
}
