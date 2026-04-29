<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\Actions\NormalizeGitHubPullRequestAction;
use Tests\TestCase;

class NormalizeGitHubPullRequestActionTest extends TestCase
{
    private NormalizeGitHubPullRequestAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new NormalizeGitHubPullRequestAction;
    }

    public function test_normalizes_a_full_pr_payload(): void
    {
        $payload = [
            'id' => 999_888,
            'number' => 17,
            'title' => 'Add login retry logic',
            'body' => str_repeat('x', 500),
            'state' => 'open',
            'user' => ['login' => 'octocat'],
            'base' => ['ref' => 'main'],
            'head' => ['ref' => 'feature/login-retry'],
            'draft' => false,
            'merged' => false,
            'merged_at' => null,
            'additions' => 42,
            'deletions' => 7,
            'changed_files' => 3,
            'comments' => 4,
            'review_comments' => 2,
            'created_at' => '2026-04-10T10:00:00Z',
            'updated_at' => '2026-04-15T12:00:00Z',
            'closed_at' => null,
        ];

        $result = $this->action->execute($payload);

        $this->assertNotNull($result);
        $this->assertSame(999_888, $result['github_id']);
        $this->assertSame(17, $result['number']);
        $this->assertSame('Add login retry logic', $result['title']);
        $this->assertSame(280, mb_strlen($result['body_preview']));
        $this->assertSame('open', $result['state']);
        $this->assertSame('octocat', $result['author_login']);
        $this->assertSame('main', $result['base_branch']);
        $this->assertSame('feature/login-retry', $result['head_branch']);
        $this->assertFalse($result['draft']);
        $this->assertFalse($result['merged']);
        $this->assertSame(42, $result['additions']);
        $this->assertSame(2, $result['review_comments_count']);
        $this->assertNotNull($result['created_at_github']);
        $this->assertNull($result['merged_at']);
    }

    public function test_derives_state_open_when_state_is_open_and_not_merged(): void
    {
        $result = $this->action->execute([
            'id' => 1,
            'number' => 1,
            'title' => 'open PR',
            'state' => 'open',
            'merged' => false,
        ]);

        $this->assertSame('open', $result['state']);
        $this->assertFalse($result['merged']);
    }

    public function test_derives_state_closed_when_state_is_closed_and_not_merged(): void
    {
        $result = $this->action->execute([
            'id' => 2,
            'number' => 2,
            'title' => 'closed PR',
            'state' => 'closed',
            'merged' => false,
            'merged_at' => null,
        ]);

        $this->assertSame('closed', $result['state']);
        $this->assertFalse($result['merged']);
    }

    public function test_derives_state_merged_when_merged_flag_true(): void
    {
        $result = $this->action->execute([
            'id' => 3,
            'number' => 3,
            'title' => 'merged via flag',
            'state' => 'closed',
            'merged' => true,
            'merged_at' => '2026-04-20T00:00:00Z',
        ]);

        $this->assertSame('merged', $result['state']);
        $this->assertTrue($result['merged']);
        $this->assertNotNull($result['merged_at']);
    }

    public function test_derives_state_merged_when_merged_at_set_without_flag(): void
    {
        // Some payloads ship `merged_at` non-null without an explicit
        // `merged: true` field. Trust either signal.
        $result = $this->action->execute([
            'id' => 4,
            'number' => 4,
            'title' => 'merged via timestamp',
            'state' => 'closed',
            'merged_at' => '2026-04-20T00:00:00Z',
        ]);

        $this->assertSame('merged', $result['state']);
        $this->assertTrue($result['merged']);
    }

    public function test_handles_missing_optional_fields_gracefully(): void
    {
        $result = $this->action->execute([
            'id' => 5,
            'number' => 1,
            'title' => 'Minimal PR',
        ]);

        $this->assertNotNull($result);
        $this->assertSame('open', $result['state']);
        $this->assertNull($result['author_login']);
        $this->assertSame('', $result['base_branch']);
        $this->assertSame('', $result['head_branch']);
        $this->assertFalse($result['draft']);
        $this->assertFalse($result['merged']);
        $this->assertSame(0, $result['additions']);
        $this->assertNull($result['body_preview']);
        $this->assertNull($result['merged_at']);
    }

    public function test_returns_null_when_required_fields_missing(): void
    {
        $this->assertNull($this->action->execute(['id' => 7]));
        $this->assertNull($this->action->execute(['id' => 7, 'number' => 1]));
        $this->assertNull($this->action->execute(['number' => 1, 'title' => 'No id']));
    }
}
