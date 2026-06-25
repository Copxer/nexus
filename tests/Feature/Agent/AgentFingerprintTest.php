<?php

namespace Tests\Feature\Agent;

use App\Domain\Docker\Actions\IssueAgentTokenAction;
use App\Domain\Docker\Actions\RotateAgentTokenAction;
use App\Http\Middleware\AuthenticateAgent;
use App\Models\ActivityEvent;
use App\Models\Host;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Spec 039 — opt-in fingerprint binding on agent tokens.
 *
 *   - Off by default: no binding, no rejection.
 *   - On + null hash: first request binds (sha256 of IP + UA).
 *   - On + matching hash: passes.
 *   - On + mismatched hash: 401 + `agent.auth.failure` event with
 *     reason `fingerprint_mismatch`.
 *   - Rotation preserves the opt-in but resets the hash.
 */
class AgentFingerprintTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'recorded_at' => now()->toIso8601String(),
            'host' => ['metrics' => ['cpu_percent' => null]],
        ];
    }

    public function test_fingerprint_disabled_token_is_not_bound(): void
    {
        $host = Host::factory()->create();
        $result = app(IssueAgentTokenAction::class)->execute($host);

        $this->assertFalse($result->token->fresh()->fingerprint_enabled);

        // Two different "clients" both pass — no binding kicks in.
        $this->postJson(
            route('agent.telemetry'),
            $this->payload(),
            [
                'Authorization' => 'Bearer '.$result->plaintext,
                'User-Agent' => 'agent-one',
            ],
        )->assertNoContent();

        $this->postJson(
            route('agent.telemetry'),
            $this->payload(),
            [
                'Authorization' => 'Bearer '.$result->plaintext,
                'User-Agent' => 'agent-two',
            ],
        )->assertNoContent();

        $this->assertNull($result->token->fresh()->fingerprint_hash);
    }

    public function test_first_request_binds_fingerprint_when_opt_in_is_set(): void
    {
        $host = Host::factory()->create();
        $result = app(IssueAgentTokenAction::class)->execute(
            $host,
            null,
            null,
            fingerprintEnabled: true,
        );

        $this->assertTrue($result->token->fresh()->fingerprint_enabled);
        $this->assertNull($result->token->fresh()->fingerprint_hash);

        $this->postJson(
            route('agent.telemetry'),
            $this->payload(),
            [
                'Authorization' => 'Bearer '.$result->plaintext,
                'User-Agent' => 'agent-binary/1.0',
            ],
        )->assertNoContent();

        $bound = $result->token->fresh();
        $this->assertNotNull($bound->fingerprint_hash);
        $this->assertSame(64, strlen($bound->fingerprint_hash), 'sha256 hex digest');
    }

    public function test_matching_fingerprint_passes_on_subsequent_request(): void
    {
        $host = Host::factory()->create();
        $result = app(IssueAgentTokenAction::class)->execute(
            $host,
            null,
            null,
            fingerprintEnabled: true,
        );

        // First request binds.
        $this->postJson(
            route('agent.telemetry'),
            $this->payload(),
            [
                'Authorization' => 'Bearer '.$result->plaintext,
                'User-Agent' => 'agent-binary/1.0',
            ],
        )->assertNoContent();

        // Second request from the same client passes.
        $this->postJson(
            route('agent.telemetry'),
            $this->payload(),
            [
                'Authorization' => 'Bearer '.$result->plaintext,
                'User-Agent' => 'agent-binary/1.0',
            ],
        )->assertNoContent();
    }

    public function test_mismatched_fingerprint_returns_401_and_dispatches_failure_event(): void
    {
        $host = Host::factory()->create();
        $result = app(IssueAgentTokenAction::class)->execute(
            $host,
            null,
            null,
            fingerprintEnabled: true,
        );

        // First request binds.
        $this->postJson(
            route('agent.telemetry'),
            $this->payload(),
            [
                'Authorization' => 'Bearer '.$result->plaintext,
                'User-Agent' => 'agent-binary/1.0',
            ],
        )->assertNoContent();

        // Drop the existing event so we only see the mismatch one.
        ActivityEvent::query()->delete();

        // Second request from a different client gets rejected.
        $this->postJson(
            route('agent.telemetry'),
            $this->payload(),
            [
                'Authorization' => 'Bearer '.$result->plaintext,
                'User-Agent' => 'imposter/2.0',
            ],
        )->assertStatus(401);

        $event = ActivityEvent::query()
            ->where('event_type', 'agent.auth.failure')
            ->firstOrFail();
        $this->assertSame('fingerprint_mismatch', $event->metadata['reason']);
    }

    public function test_fingerprint_helper_is_deterministic(): void
    {
        $request1 = Request::create('/agent/telemetry', 'POST');
        $request1->server->set('REMOTE_ADDR', '127.0.0.1');
        $request1->headers->set('User-Agent', 'agent-binary/1.0');

        $request2 = Request::create('/agent/telemetry', 'POST');
        $request2->server->set('REMOTE_ADDR', '127.0.0.1');
        $request2->headers->set('User-Agent', 'agent-binary/1.0');

        $this->assertSame(
            AuthenticateAgent::fingerprint($request1),
            AuthenticateAgent::fingerprint($request2),
        );
    }

    public function test_rotation_preserves_opt_in_and_resets_hash(): void
    {
        $host = Host::factory()->create();
        $result = app(IssueAgentTokenAction::class)->execute(
            $host,
            null,
            null,
            fingerprintEnabled: true,
        );

        // Bind.
        $this->postJson(
            route('agent.telemetry'),
            $this->payload(),
            [
                'Authorization' => 'Bearer '.$result->plaintext,
                'User-Agent' => 'agent-binary/1.0',
            ],
        )->assertNoContent();

        $this->assertNotNull($result->token->fresh()->fingerprint_hash);

        // Rotate.
        $rotated = app(RotateAgentTokenAction::class)->execute($host);

        $this->assertTrue($rotated->token->fingerprint_enabled, 'opt-in carries forward');
        $this->assertNull($rotated->token->fingerprint_hash, 'hash resets — re-bind on next request');
    }
}
