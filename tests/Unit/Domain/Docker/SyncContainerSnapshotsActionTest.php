<?php

namespace Tests\Unit\Domain\Docker;

use App\Domain\Docker\Actions\SyncContainerSnapshotsAction;
use App\Models\Container;
use App\Models\ContainerMetricSnapshot;
use App\Models\Host;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncContainerSnapshotsActionTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $id = 'abc123', array $overrides = []): array
    {
        return array_replace_recursive([
            'container_id' => $id,
            'name' => 'web',
            'image' => 'ghcr.io/acme/web',
            'image_tag' => 'v1',
            'status' => 'running',
            'state' => 'running',
            'health_status' => 'healthy',
            'ports' => [],
            'labels' => [],
            'metrics' => [
                'cpu_percent' => 1.5,
                'memory_usage_mb' => 100,
                'memory_limit_mb' => 400,
            ],
        ], $overrides);
    }

    public function test_first_call_inserts_container_and_snapshot(): void
    {
        $host = Host::factory()->create();

        app(SyncContainerSnapshotsAction::class)->execute(
            $host,
            CarbonImmutable::now(),
            [$this->payload()],
        );

        $this->assertSame(1, Container::query()->count());
        $this->assertSame(1, ContainerMetricSnapshot::query()->count());

        $container = Container::query()->firstOrFail();
        $this->assertSame('abc123', $container->container_id);
        $this->assertSame(25.0, (float) $container->memory_percent);
    }

    public function test_repeat_call_upserts_container_and_appends_snapshot(): void
    {
        $host = Host::factory()->create();
        $sync = app(SyncContainerSnapshotsAction::class);

        $sync->execute($host, CarbonImmutable::now()->subMinutes(1), [$this->payload()]);
        $sync->execute(
            $host,
            CarbonImmutable::now(),
            [$this->payload('abc123', ['metrics' => ['cpu_percent' => 9.9]])],
        );

        $this->assertSame(1, Container::query()->count(), 'container deduped');
        $this->assertSame(2, ContainerMetricSnapshot::query()->count());
        $this->assertSame(9.9, (float) Container::query()->first()->cpu_percent);
    }

    public function test_missing_container_in_payload_does_not_drop_existing_row(): void
    {
        $host = Host::factory()->create();
        $sync = app(SyncContainerSnapshotsAction::class);

        $sync->execute($host, CarbonImmutable::now()->subMinutes(1), [
            $this->payload('abc'),
            $this->payload('xyz'),
        ]);

        // Second call: only one container.
        $sync->execute($host, CarbonImmutable::now(), [$this->payload('abc')]);

        $this->assertSame(2, Container::query()->count(), 'rows persist when omitted');
    }

    public function test_memory_percent_is_null_when_inputs_are_missing(): void
    {
        $host = Host::factory()->create();

        app(SyncContainerSnapshotsAction::class)->execute(
            $host,
            CarbonImmutable::now(),
            [$this->payload('abc', ['metrics' => ['memory_usage_mb' => null, 'memory_limit_mb' => null]])],
        );

        $container = Container::query()->firstOrFail();
        $this->assertNull($container->memory_percent);
    }

    public function test_same_container_id_on_two_hosts_creates_two_distinct_rows(): void
    {
        $hostA = Host::factory()->create();
        $hostB = Host::factory()->create();
        $sync = app(SyncContainerSnapshotsAction::class);

        // Both hosts run a container with the literal id 'abc'. The
        // unique index on (host_id, container_id) means each gets its
        // own row and snapshot — host A's data must not bleed into
        // host B's.
        $sync->execute($hostA, CarbonImmutable::now(), [
            $this->payload('abc', ['name' => 'host-a-web']),
        ]);
        $sync->execute($hostB, CarbonImmutable::now(), [
            $this->payload('abc', ['name' => 'host-b-web']),
        ]);

        $this->assertSame(2, Container::query()->count());
        $this->assertSame('host-a-web', Container::query()->where('host_id', $hostA->id)->value('name'));
        $this->assertSame('host-b-web', Container::query()->where('host_id', $hostB->id)->value('name'));
    }
}
