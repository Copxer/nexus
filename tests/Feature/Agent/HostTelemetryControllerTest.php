<?php

namespace Tests\Feature\Agent;

use App\Domain\Docker\Actions\IssueAgentTokenAction;
use App\Enums\HostStatus;
use App\Http\Middleware\AuthenticateAgent;
use App\Models\AgentToken;
use App\Models\Container;
use App\Models\ContainerMetricSnapshot;
use App\Models\Host;
use App\Models\HostMetricSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class HostTelemetryControllerTest extends TestCase
{
    use RefreshDatabase;

    private function issuedToken(?Host $host = null): array
    {
        $host ??= Host::factory()->create();
        $result = app(IssueAgentTokenAction::class)->execute($host);

        return [$host->fresh(), $result->plaintext];
    }

    private function fullPayload(): array
    {
        return [
            'recorded_at' => now()->toIso8601String(),
            'host' => [
                'facts' => [
                    'cpu_count' => 4,
                    'memory_total_mb' => 8192,
                    'disk_total_gb' => 100,
                    'os' => 'Ubuntu 24.04',
                    'docker_version' => '26.1.0',
                ],
                'metrics' => [
                    'cpu_percent' => 23.4,
                    'memory_used_mb' => 4096,
                    'memory_total_mb' => 8192,
                    'load_average' => 0.82,
                    'network_rx_bytes' => 12_000_000,
                    'network_tx_bytes' => 5_000_000,
                ],
            ],
            'containers' => [
                [
                    'container_id' => 'abc123',
                    'name' => 'web',
                    'image' => 'ghcr.io/acme/web',
                    'image_tag' => 'v1.2.3',
                    'status' => 'running',
                    'state' => 'running',
                    'health_status' => 'healthy',
                    'ports' => ['0.0.0.0:8080->80/tcp'],
                    'labels' => ['svc' => 'web'],
                    'metrics' => [
                        'cpu_percent' => 4.21,
                        'memory_usage_mb' => 128,
                        'memory_limit_mb' => 512,
                        'network_rx_bytes' => 1_024_000,
                        'network_tx_bytes' => 512_000,
                        'block_read_bytes' => 0,
                        'block_write_bytes' => 0,
                    ],
                ],
            ],
        ];
    }

    public function test_happy_path_persists_host_and_container_state(): void
    {
        [$host, $plaintext] = $this->issuedToken();

        $this->postJson(
            route('agent.telemetry'),
            $this->fullPayload(),
            ['Authorization' => 'Bearer '.$plaintext],
        )->assertNoContent();

        $host->refresh();
        $this->assertSame(HostStatus::Online, $host->status);
        $this->assertNotNull($host->last_seen_at);
        $this->assertSame(4, $host->cpu_count);
        $this->assertSame(8192, $host->memory_total_mb);
        $this->assertSame('26.1.0', $host->docker_version);

        $this->assertSame(1, HostMetricSnapshot::query()->count());
        $this->assertSame(1, Container::query()->count());
        $this->assertSame(1, ContainerMetricSnapshot::query()->count());

        $container = Container::query()->firstOrFail();
        $this->assertSame('web', $container->name);
        $this->assertSame(25.0, (float) $container->memory_percent); // 128/512 * 100
    }

    public function test_second_post_appends_snapshots_without_duplicating_container(): void
    {
        [$host, $plaintext] = $this->issuedToken();

        $this->postJson(
            route('agent.telemetry'),
            $this->fullPayload(),
            ['Authorization' => 'Bearer '.$plaintext],
        )->assertNoContent();

        $second = $this->fullPayload();
        $second['recorded_at'] = now()->addSeconds(31)->toIso8601String();
        $second['containers'][0]['metrics']['cpu_percent'] = 9.99;

        $this->postJson(
            route('agent.telemetry'),
            $second,
            ['Authorization' => 'Bearer '.$plaintext],
        )->assertNoContent();

        $this->assertSame(1, Container::query()->count(), 'container row deduped on (host_id, container_id)');
        $this->assertSame(2, HostMetricSnapshot::query()->count());
        $this->assertSame(2, ContainerMetricSnapshot::query()->count());
        $this->assertSame(9.99, (float) Container::query()->first()->cpu_percent);
    }

    public function test_validation_rejects_payload_with_invalid_metrics(): void
    {
        [, $plaintext] = $this->issuedToken();

        $payload = $this->fullPayload();
        $payload['host']['metrics']['cpu_percent'] = 250; // out of [0,100]

        $this->postJson(
            route('agent.telemetry'),
            $payload,
            ['Authorization' => 'Bearer '.$plaintext],
        )->assertStatus(422);

        $this->assertSame(0, HostMetricSnapshot::query()->count());
    }

    public function test_validation_rejects_skewed_recorded_at(): void
    {
        [, $plaintext] = $this->issuedToken();

        $payload = $this->fullPayload();
        $payload['recorded_at'] = now()->subHours(2)->toIso8601String();

        $this->postJson(
            route('agent.telemetry'),
            $payload,
            ['Authorization' => 'Bearer '.$plaintext],
        )->assertStatus(422)
            ->assertJsonValidationErrors('recorded_at');
    }

    public function test_rate_limit_returns_429_with_retry_after(): void
    {
        [, $plaintext] = $this->issuedToken();

        // Pre-fill the bucket up to the limit. Cheaper than firing 60
        // real requests, and exercises the same key the middleware uses
        // (the static `rateLimitKey` helper is the public contract).
        $token = AgentToken::query()->latest('id')->firstOrFail();
        $key = AuthenticateAgent::rateLimitKey($token);
        for ($i = 0; $i < AuthenticateAgent::RATE_LIMIT_PER_MINUTE; $i++) {
            RateLimiter::hit($key, 60);
        }

        $this->postJson(
            route('agent.telemetry'),
            $this->fullPayload(),
            ['Authorization' => 'Bearer '.$plaintext],
        )
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_rate_limit_buckets_per_token(): void
    {
        [$hostA, $tokenA] = $this->issuedToken();
        [, $tokenB] = $this->issuedToken(Host::factory()->create());

        // Burn A's bucket completely; B's bucket is independent.
        $tokenA_model = AgentToken::query()->where('host_id', $hostA->id)->latest('id')->firstOrFail();
        $keyA = AuthenticateAgent::rateLimitKey($tokenA_model);
        for ($i = 0; $i < AuthenticateAgent::RATE_LIMIT_PER_MINUTE; $i++) {
            RateLimiter::hit($keyA, 60);
        }

        $this->postJson(route('agent.telemetry'), $this->fullPayload(), ['Authorization' => 'Bearer '.$tokenA])
            ->assertStatus(429);

        $this->postJson(route('agent.telemetry'), $this->fullPayload(), ['Authorization' => 'Bearer '.$tokenB])
            ->assertNoContent();
    }

    public function test_rate_limit_does_not_advance_last_used_at(): void
    {
        [, $plaintext] = $this->issuedToken();

        $token = AgentToken::query()->latest('id')->firstOrFail();
        $key = AuthenticateAgent::rateLimitKey($token);
        for ($i = 0; $i < AuthenticateAgent::RATE_LIMIT_PER_MINUTE; $i++) {
            RateLimiter::hit($key, 60);
        }

        $before = $token->last_used_at;

        $this->postJson(
            route('agent.telemetry'),
            $this->fullPayload(),
            ['Authorization' => 'Bearer '.$plaintext],
        )->assertStatus(429);

        $this->assertEquals($before, $token->fresh()->last_used_at);
    }
}
