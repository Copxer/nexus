<?php

namespace Tests\Feature\Security;

use App\Models\GithubConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Spec 039 — pin the secret-handling invariants for the GitHub
 * OAuth connection. A future refactor that drops `$hidden` or
 * changes the `encrypted` cast will trip these tests instead of
 * shipping a leak.
 */
class GithubConnectionSecretTest extends TestCase
{
    use RefreshDatabase;

    private function connection(): GithubConnection
    {
        $user = User::factory()->create();

        return GithubConnection::query()->create([
            'user_id' => $user->id,
            'github_user_id' => '9001',
            'github_username' => 'octocat',
            'access_token' => 'gho_plaintext_access',
            'refresh_token' => 'ghr_plaintext_refresh',
            'expires_at' => now()->addHours(8),
            'connected_at' => now(),
        ]);
    }

    public function test_access_token_is_encrypted_at_rest(): void
    {
        $connection = $this->connection();

        // Raw DB row — bypass the model's `encrypted` cast to inspect
        // what actually lives on disk.
        $raw = DB::table('github_connections')->where('id', $connection->id)->first();

        $this->assertNotNull($raw->access_token);
        $this->assertNotSame(
            'gho_plaintext_access',
            $raw->access_token,
            'Plaintext token must never land in the column.',
        );
        // Confirm the model decrypts it back.
        $this->assertSame('gho_plaintext_access', $connection->fresh()->access_token);
    }

    public function test_refresh_token_is_encrypted_at_rest(): void
    {
        $connection = $this->connection();
        $raw = DB::table('github_connections')->where('id', $connection->id)->first();

        $this->assertNotNull($raw->refresh_token);
        $this->assertNotSame('ghr_plaintext_refresh', $raw->refresh_token);
        $this->assertSame('ghr_plaintext_refresh', $connection->fresh()->refresh_token);
    }

    public function test_tokens_are_hidden_from_array_serialization(): void
    {
        $connection = $this->connection();
        $serialized = $connection->fresh()->toArray();

        $this->assertArrayNotHasKey('access_token', $serialized);
        $this->assertArrayNotHasKey('refresh_token', $serialized);
    }

    public function test_tokens_are_hidden_from_json_serialization(): void
    {
        $connection = $this->connection();
        $json = $connection->fresh()->toJson();

        $this->assertStringNotContainsString('gho_plaintext_access', $json);
        $this->assertStringNotContainsString('ghr_plaintext_refresh', $json);
    }
}
