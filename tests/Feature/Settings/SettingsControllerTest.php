<?php

namespace Tests\Feature\Settings;

use App\Models\GithubConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_settings_page_renders_disconnected_state_for_users_without_a_connection(): void
    {
        $this->actingAs($this->verifiedUser())
            ->get(route('settings.index'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Settings/Index')
                    ->where('github', null)
            );
    }

    public function test_settings_page_renders_connected_state_when_a_connection_exists(): void
    {
        $user = $this->verifiedUser();
        GithubConnection::query()->create([
            'user_id' => $user->id,
            'github_user_id' => '9001',
            'github_username' => 'octocat',
            'access_token' => 'gho_token',
            'scopes' => ['read:user', 'repo'],
            'expires_at' => now()->addHours(8),
            'connected_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertSuccessful()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Settings/Index')
                    ->where('github.username', 'octocat')
                    ->where('github.is_token_valid', true)
                    ->where('github.scopes', ['read:user', 'repo'])
            );
    }

    public function test_settings_page_does_not_leak_tokens_in_inertia_props(): void
    {
        $user = $this->verifiedUser();
        GithubConnection::query()->create([
            'user_id' => $user->id,
            'github_user_id' => '9001',
            'github_username' => 'octocat',
            'access_token' => 'gho_super_secret_token',
            'refresh_token' => 'ghr_super_secret_refresh',
            'connected_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('settings.index'));
        $body = $response->getContent();

        $this->assertStringNotContainsString('gho_super_secret_token', $body);
        $this->assertStringNotContainsString('ghr_super_secret_refresh', $body);
    }
}
