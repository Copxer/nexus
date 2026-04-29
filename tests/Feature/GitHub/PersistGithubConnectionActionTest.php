<?php

namespace Tests\Feature\GitHub;

use App\Domain\GitHub\Actions\PersistGithubConnectionAction;
use App\Models\GithubConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PersistGithubConnectionActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_connection_for_a_user_with_no_prior_link(): void
    {
        $user = User::factory()->create();

        $connection = (new PersistGithubConnectionAction)->execute(
            $user,
            [
                'access_token' => 'gho_abc',
                'refresh_token' => 'ghr_refresh',
                'scope' => 'read:user,repo',
                'expires_in' => 28800,
                'refresh_token_expires_in' => 15897600,
            ],
            ['id' => 9001, 'login' => 'octocat'],
        );

        $this->assertSame($user->id, $connection->user_id);
        $this->assertSame('octocat', $connection->github_username);
        $this->assertSame('9001', $connection->github_user_id);
        $this->assertSame(['read:user', 'repo'], $connection->scopes);

        // Encrypted casts decrypt transparently on read.
        $this->assertSame('gho_abc', $connection->access_token);
        $this->assertSame('ghr_refresh', $connection->refresh_token);

        // Database stored the encrypted blobs, not the plaintext —
        // read raw via the query builder to bypass the model casts.
        $row = DB::table('github_connections')->where('user_id', $user->id)->first();
        $this->assertNotSame('gho_abc', $row->access_token);
        $this->assertNotSame('ghr_refresh', $row->refresh_token);

        $this->assertNotNull($connection->expires_at);
        $this->assertNotNull($connection->refresh_token_expires_at);
        $this->assertNotNull($connection->connected_at);
    }

    public function test_re_running_updates_the_existing_row(): void
    {
        $user = User::factory()->create();
        $action = new PersistGithubConnectionAction;

        $first = $action->execute(
            $user,
            ['access_token' => 'token-a', 'scope' => 'read:user'],
            ['id' => 1, 'login' => 'octocat'],
        );

        $second = $action->execute(
            $user,
            ['access_token' => 'token-b', 'scope' => 'read:user,repo'],
            ['id' => 1, 'login' => 'octocat'],
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame('token-b', $second->access_token);
        $this->assertSame(['read:user', 'repo'], $second->scopes);
        $this->assertSame(
            1,
            GithubConnection::query()->where('user_id', $user->id)->count(),
        );
    }

    public function test_handles_missing_optional_fields_gracefully(): void
    {
        $user = User::factory()->create();

        // GitHub OAuth Apps (vs GitHub Apps) don't return refresh_token,
        // expires_in, or refresh_token_expires_in. Action should cope.
        $connection = (new PersistGithubConnectionAction)->execute(
            $user,
            ['access_token' => 'gho_no_expiry'],
            ['id' => 42, 'login' => 'octobot'],
        );

        $this->assertNull($connection->refresh_token);
        $this->assertNull($connection->expires_at);
        $this->assertNull($connection->refresh_token_expires_at);
        $this->assertNull($connection->scopes);
    }
}
