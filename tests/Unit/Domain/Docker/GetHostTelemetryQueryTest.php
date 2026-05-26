<?php

namespace Tests\Unit\Domain\Docker;

use App\Domain\Docker\Queries\GetHostTelemetryQuery;
use App\Models\Container;
use App\Models\Host;
use App\Models\HostMetricSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetHostTelemetryQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_null_current_and_empty_arrays_for_a_host_without_snapshots(): void
    {
        $host = Host::factory()->create();

        $result = app(GetHostTelemetryQuery::class)->execute($host);

        $this->assertNull($result['current']);
        $this->assertSame([], $result['series']);
        $this->assertSame([], $result['containers']);
    }

    public function test_series_orders_oldest_to_newest_and_caps_at_50(): void
    {
        $host = Host::factory()->create();

        // 60 snapshots, recorded_at strictly increasing. The query
        // keeps the 50 newest and emits them oldest→newest.
        for ($i = 1; $i <= 60; $i++) {
            HostMetricSnapshot::factory()->create([
                'host_id' => $host->id,
                'cpu_percent' => (float) $i,
                'memory_used_mb' => null,
                'memory_total_mb' => null,
                'recorded_at' => now()->subMinutes(60 - $i),
            ]);
        }

        $result = app(GetHostTelemetryQuery::class)->execute($host);

        $this->assertCount(50, $result['series']);
        // Oldest of the 50 newest is i = 11; newest is i = 60.
        $this->assertSame(11.0, $result['series'][0]['cpu_percent']);
        $this->assertSame(60.0, $result['series'][49]['cpu_percent']);
        // `current` is the latest of all.
        $this->assertSame(60.0, $result['current']['cpu_percent']);
    }

    public function test_memory_percent_is_derived_when_both_sides_present_else_null(): void
    {
        $host = Host::factory()->create();
        HostMetricSnapshot::factory()->create([
            'host_id' => $host->id,
            'memory_used_mb' => 250,
            'memory_total_mb' => 1000,
            'recorded_at' => now()->subMinute(),
        ]);
        HostMetricSnapshot::factory()->create([
            'host_id' => $host->id,
            'memory_used_mb' => null,
            'memory_total_mb' => 1000,
            'recorded_at' => now(),
        ]);

        $result = app(GetHostTelemetryQuery::class)->execute($host);

        // Series ordered oldest→newest.
        $this->assertSame(25.0, $result['series'][0]['memory_percent']);
        $this->assertNull($result['series'][1]['memory_percent']);
        // `current` is the latest row, whose memory_used_mb is null.
        $this->assertNull($result['current']['memory_percent']);
    }

    public function test_containers_are_ordered_by_name(): void
    {
        $host = Host::factory()->create();
        Container::factory()->create(['host_id' => $host->id, 'name' => 'web']);
        Container::factory()->create(['host_id' => $host->id, 'name' => 'api']);
        Container::factory()->create(['host_id' => $host->id, 'name' => 'db']);

        $result = app(GetHostTelemetryQuery::class)->execute($host);

        $this->assertSame(
            ['api', 'db', 'web'],
            array_column($result['containers'], 'name'),
        );
    }

    public function test_containers_from_another_host_do_not_leak(): void
    {
        $host = Host::factory()->create();
        $otherHost = Host::factory()->create();
        Container::factory()->create(['host_id' => $host->id, 'name' => 'mine']);
        Container::factory()->create(['host_id' => $otherHost->id, 'name' => 'theirs']);

        $result = app(GetHostTelemetryQuery::class)->execute($host);

        $this->assertCount(1, $result['containers']);
        $this->assertSame('mine', $result['containers'][0]['name']);
    }
}
