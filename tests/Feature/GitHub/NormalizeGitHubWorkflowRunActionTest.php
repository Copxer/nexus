<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\Actions\NormalizeGitHubWorkflowRunAction;
use Tests\TestCase;

class NormalizeGitHubWorkflowRunActionTest extends TestCase
{
    private NormalizeGitHubWorkflowRunAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new NormalizeGitHubWorkflowRunAction;
    }

    public function test_normalizes_a_completed_run_payload(): void
    {
        $payload = [
            'id' => 17_002_345_678,
            'run_number' => 142,
            'name' => 'Deploy production',
            'event' => 'workflow_dispatch',
            'status' => 'completed',
            'conclusion' => 'success',
            'head_branch' => 'main',
            'head_sha' => 'a'.str_repeat('1', 39),
            'actor' => ['login' => 'octocat'],
            'html_url' => 'https://github.com/oct/repo/actions/runs/17002345678',
            'run_started_at' => '2026-04-29T12:00:00Z',
            'updated_at' => '2026-04-29T12:08:00Z',
            'created_at' => '2026-04-29T11:59:00Z',
        ];

        $result = $this->action->execute($payload);

        $this->assertNotNull($result);
        $this->assertSame(17_002_345_678, $result['github_id']);
        $this->assertSame(142, $result['run_number']);
        $this->assertSame('Deploy production', $result['name']);
        $this->assertSame('workflow_dispatch', $result['event']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame('success', $result['conclusion']);
        $this->assertSame('main', $result['head_branch']);
        $this->assertSame('a'.str_repeat('1', 39), $result['head_sha']);
        $this->assertSame('octocat', $result['actor_login']);
        $this->assertSame('https://github.com/oct/repo/actions/runs/17002345678', $result['html_url']);
        $this->assertNotNull($result['run_started_at']);
        $this->assertNotNull($result['run_updated_at']);
        // Status is `completed`, so `run_completed_at` mirrors `updated_at`.
        $this->assertNotNull($result['run_completed_at']);
        $this->assertSame(
            $result['run_updated_at']->toIso8601String(),
            $result['run_completed_at']->toIso8601String(),
        );
    }

    public function test_in_progress_run_has_null_completed_at_and_null_conclusion(): void
    {
        $payload = [
            'id' => 1,
            'head_sha' => 'b'.str_repeat('2', 39),
            'status' => 'in_progress',
            'conclusion' => null,
            'run_started_at' => '2026-04-29T12:00:00Z',
            'updated_at' => '2026-04-29T12:01:00Z',
            'html_url' => 'https://github.com/x/y/actions/runs/1',
        ];

        $result = $this->action->execute($payload);

        $this->assertNotNull($result);
        $this->assertSame('in_progress', $result['status']);
        $this->assertNull($result['conclusion']);
        $this->assertNull($result['run_completed_at']);
        $this->assertNotNull($result['run_started_at']);
    }

    public function test_returns_null_when_id_is_missing(): void
    {
        $this->assertNull($this->action->execute([
            'head_sha' => 'a'.str_repeat('1', 39),
        ]));
    }

    public function test_returns_null_when_head_sha_is_missing(): void
    {
        $this->assertNull($this->action->execute([
            'id' => 1,
        ]));
    }

    public function test_falls_back_to_triggering_actor_login(): void
    {
        $payload = [
            'id' => 1,
            'head_sha' => 'c'.str_repeat('3', 39),
            'status' => 'completed',
            'conclusion' => 'success',
            'triggering_actor' => ['login' => 'fallback-bot'],
            'html_url' => 'https://github.com/x/y/actions/runs/1',
        ];

        $result = $this->action->execute($payload);

        $this->assertNotNull($result);
        $this->assertSame('fallback-bot', $result['actor_login']);
    }

    public function test_unknown_status_collapses_to_queued(): void
    {
        $payload = [
            'id' => 1,
            'head_sha' => 'd'.str_repeat('4', 39),
            'status' => 'requested',
            'html_url' => 'https://github.com/x/y/actions/runs/1',
        ];

        $result = $this->action->execute($payload);

        $this->assertNotNull($result);
        $this->assertSame('queued', $result['status']);
    }

    public function test_unknown_conclusion_is_dropped(): void
    {
        $payload = [
            'id' => 1,
            'head_sha' => 'e'.str_repeat('5', 39),
            'status' => 'completed',
            'conclusion' => 'something_new',
            'html_url' => 'https://github.com/x/y/actions/runs/1',
        ];

        $result = $this->action->execute($payload);

        $this->assertNotNull($result);
        $this->assertNull($result['conclusion']);
    }

    public function test_falls_back_to_created_at_when_run_started_at_missing(): void
    {
        $payload = [
            'id' => 1,
            'head_sha' => 'f'.str_repeat('6', 39),
            'status' => 'completed',
            'conclusion' => 'success',
            'created_at' => '2026-04-29T11:59:00Z',
            'updated_at' => '2026-04-29T12:08:00Z',
            'html_url' => 'https://github.com/x/y/actions/runs/1',
        ];

        $result = $this->action->execute($payload);

        $this->assertNotNull($result);
        $this->assertNotNull($result['run_started_at']);
    }
}
