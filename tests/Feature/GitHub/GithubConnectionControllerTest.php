<?php

namespace Tests\Feature\GitHub;

use App\Models\GithubConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubConnectionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_redirect_sends_user_to_github_with_state_in_session(): void
    {
        config([
            'services.github.client_id' => 'client-abc',
            'services.github.client_secret' => 'secret-xyz',
            'services.github.redirect' => 'https://nexus.test/integrations/github/callback',
            'services.github.scopes' => ['read:user', 'repo'],
        ]);

        $response = $this->actingAs($this->verifiedUser())
            ->get(route('integrations.github.connect'));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://github.com/login/oauth/authorize?', $location);

        $state = session('github_oauth_state');
        $this->assertNotNull($state);
        $this->assertStringContainsString('state='.$state, $location);
    }

    public function test_callback_rejects_mismatched_state(): void
    {
        $user = $this->verifiedUser();

        $this->actingAs($user)
            ->withSession(['github_oauth_state' => 'expected-state'])
            ->get(route('integrations.github.callback', [
                'state' => 'different-state',
                'code' => 'some-code',
            ]))
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, GithubConnection::query()->count());
    }

    public function test_callback_happy_path_persists_the_connection(): void
    {
        config([
            'services.github.client_id' => 'client-abc',
            'services.github.client_secret' => 'secret-xyz',
            'services.github.redirect' => 'https://nexus.test/integrations/github/callback',
            'services.github.scopes' => ['read:user', 'repo'],
        ]);

        Http::fake([
            'github.com/login/oauth/access_token' => Http::response([
                'access_token' => 'gho_callback_token',
                'token_type' => 'bearer',
                'scope' => 'read:user,repo',
                'expires_in' => 28800,
            ]),
            'api.github.com/user' => Http::response([
                'id' => 9001,
                'login' => 'octocat',
            ]),
        ]);

        $user = $this->verifiedUser();

        $response = $this->actingAs($user)
            ->withSession(['github_oauth_state' => 'state-token-1'])
            ->get(route('integrations.github.callback', [
                'state' => 'state-token-1',
                'code' => 'code-from-github',
            ]));

        $response->assertRedirect(route('settings.index'))
            ->assertSessionHas('status');

        $connection = GithubConnection::query()->firstWhere('user_id', $user->id);
        $this->assertNotNull($connection);
        $this->assertSame('octocat', $connection->github_username);
        $this->assertSame('gho_callback_token', $connection->access_token);
    }

    public function test_callback_surfaces_a_github_error_query_string(): void
    {
        $user = $this->verifiedUser();

        $this->actingAs($user)
            ->withSession(['github_oauth_state' => 'state-token-1'])
            ->get(route('integrations.github.callback', [
                'state' => 'state-token-1',
                'error' => 'access_denied',
                'error_description' => 'The user has denied your application.',
            ]))
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('error');

        $this->assertSame(0, GithubConnection::query()->count());
    }

    public function test_destroy_disconnects_the_connection(): void
    {
        $user = $this->verifiedUser();
        GithubConnection::query()->create([
            'user_id' => $user->id,
            'github_user_id' => '9001',
            'github_username' => 'octocat',
            'access_token' => 'gho_to_be_deleted',
            'connected_at' => now(),
        ]);

        $this->actingAs($user)
            ->delete(route('integrations.github.disconnect'))
            ->assertRedirect(route('settings.index'))
            ->assertSessionHas('status');

        $this->assertSame(0, GithubConnection::query()->count());
    }
}
