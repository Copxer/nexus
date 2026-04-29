<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\Actions\NormalizeGitHubIssueAction;
use Tests\TestCase;

class NormalizeGitHubIssueActionTest extends TestCase
{
    private NormalizeGitHubIssueAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new NormalizeGitHubIssueAction;
    }

    public function test_drops_pull_request_payloads(): void
    {
        $payload = $this->basicIssue() + [
            'pull_request' => ['url' => 'https://api.github.com/repos/oct/repo/pulls/12'],
        ];

        $this->assertNull($this->action->execute($payload));
    }

    public function test_normalizes_a_full_issue_payload(): void
    {
        $payload = [
            'id' => 1234567,
            'number' => 42,
            'title' => 'Login intermittently fails',
            'body' => str_repeat('x', 500),
            'state' => 'open',
            'user' => ['login' => 'octocat'],
            'labels' => [
                ['name' => 'bug', 'color' => 'd73a4a', 'description' => 'should drop'],
                ['name' => 'priority:high', 'color' => 'b60205'],
                ['no name field' => true], // dropped
            ],
            'assignees' => [
                ['login' => 'alice'],
                ['login' => 'bob'],
                ['no login field' => true], // dropped
            ],
            'milestone' => [
                'title' => '1.0',
                'due_on' => '2026-05-30T00:00:00Z',
            ],
            'comments' => 5,
            'locked' => true,
            'created_at' => '2026-04-01T10:00:00Z',
            'updated_at' => '2026-04-15T12:00:00Z',
            'closed_at' => null,
        ];

        $result = $this->action->execute($payload);

        $this->assertNotNull($result);
        $this->assertSame(1234567, $result['github_id']);
        $this->assertSame(42, $result['number']);
        $this->assertSame('Login intermittently fails', $result['title']);
        $this->assertSame(280, mb_strlen($result['body_preview']));
        $this->assertSame('open', $result['state']);
        $this->assertSame('octocat', $result['author_login']);
        $this->assertSame(
            [
                ['name' => 'bug', 'color' => 'd73a4a'],
                ['name' => 'priority:high', 'color' => 'b60205'],
            ],
            $result['labels'],
        );
        $this->assertSame(['alice', 'bob'], $result['assignees']);
        $this->assertSame(['title' => '1.0', 'due_on' => '2026-05-30T00:00:00Z'], $result['milestone']);
        $this->assertSame(5, $result['comments_count']);
        $this->assertTrue($result['is_locked']);
        $this->assertNotNull($result['created_at_github']);
        $this->assertNotNull($result['updated_at_github']);
        $this->assertNull($result['closed_at_github']);
    }

    public function test_handles_missing_optional_fields_gracefully(): void
    {
        $result = $this->action->execute([
            'id' => 7,
            'number' => 1,
            'title' => 'Bare-bones issue',
        ]);

        $this->assertNotNull($result);
        $this->assertSame('open', $result['state']); // default
        $this->assertNull($result['author_login']);
        $this->assertSame([], $result['labels']);
        $this->assertSame([], $result['assignees']);
        $this->assertNull($result['milestone']);
        $this->assertSame(0, $result['comments_count']);
        $this->assertFalse($result['is_locked']);
        $this->assertNull($result['body_preview']);
        $this->assertNull($result['created_at_github']);
    }

    public function test_returns_null_when_required_fields_missing(): void
    {
        $this->assertNull($this->action->execute(['id' => 7]));
        $this->assertNull($this->action->execute(['id' => 7, 'number' => 1]));
        $this->assertNull($this->action->execute(['number' => 1, 'title' => 'No id']));
    }

    public function test_unknown_state_falls_back_to_open(): void
    {
        $result = $this->action->execute(
            $this->basicIssue() + ['state' => 'something-else'],
        );

        $this->assertSame('open', $result['state']);
    }

    /** Minimal valid issue payload — saves duplication across cases. */
    private function basicIssue(): array
    {
        return [
            'id' => 100,
            'number' => 1,
            'title' => 'Test issue',
        ];
    }
}
