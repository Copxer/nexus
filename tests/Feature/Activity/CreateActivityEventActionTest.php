<?php

namespace Tests\Feature\Activity;

use App\Domain\Activity\Actions\CreateActivityEventAction;
use App\Enums\ActivitySeverity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class CreateActivityEventActionTest extends TestCase
{
    use RefreshDatabase;

    private CreateActivityEventAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new CreateActivityEventAction;
    }

    public function test_creates_a_row_with_normalized_fields(): void
    {
        $event = $this->action->execute([
            'event_type' => 'issue.created',
            'title' => 'New login bug',
            'occurred_at' => Carbon::parse('2026-04-15T12:00:00Z'),
            'severity' => ActivitySeverity::Info,
            'actor_login' => 'alice',
            'metadata' => ['issue_number' => 42],
        ]);

        $this->assertDatabaseHas('activity_events', [
            'id' => $event->id,
            'event_type' => 'issue.created',
            'title' => 'New login bug',
            'severity' => 'info',
            'source' => 'github',
            'actor_login' => 'alice',
        ]);
        $this->assertSame(['issue_number' => 42], $event->fresh()->metadata);
    }

    public function test_accepts_string_severity(): void
    {
        $event = $this->action->execute([
            'event_type' => 'issue.closed',
            'title' => 'Closed',
            'occurred_at' => now(),
            'severity' => 'success',
        ]);

        $this->assertSame('success', $event->severity->value);
    }

    public function test_defaults_optional_fields(): void
    {
        $event = $this->action->execute([
            'event_type' => 'issue.created',
            'title' => 'Bare-bones',
            'occurred_at' => now(),
        ]);

        $this->assertSame('info', $event->severity->value);
        $this->assertSame('github', $event->source);
        $this->assertSame([], $event->metadata);
        $this->assertNull($event->actor_login);
        $this->assertNull($event->repository_id);
    }

    public function test_throws_when_event_type_is_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->action->execute([
            'title' => 'No event type',
            'occurred_at' => now(),
        ]);
    }

    public function test_throws_when_title_is_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->action->execute([
            'event_type' => 'issue.created',
            'occurred_at' => now(),
        ]);
    }

    public function test_throws_when_occurred_at_is_not_a_carbon(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->action->execute([
            'event_type' => 'issue.created',
            'title' => 'No timestamp',
            'occurred_at' => '2026-04-15T12:00:00Z',
        ]);
    }
}
