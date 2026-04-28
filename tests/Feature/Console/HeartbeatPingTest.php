<?php

namespace Tests\Feature\Console;

use App\Jobs\HeartbeatPing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HeartbeatPingTest extends TestCase
{
    public function test_app_heartbeat_command_dispatches_the_heartbeat_job(): void
    {
        Queue::fake();

        $this->artisan('app:heartbeat')
            ->expectsOutputToContain('Heartbeat ping dispatched.')
            ->assertSuccessful();

        Queue::assertPushed(HeartbeatPing::class);
    }

    public function test_heartbeat_job_handle_logs_a_ping(): void
    {
        Log::spy();

        (new HeartbeatPing)->handle();

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message) => str_starts_with($message, 'Heartbeat ping at '));
    }
}
