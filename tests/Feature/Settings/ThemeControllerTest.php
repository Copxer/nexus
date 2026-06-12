<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_theme_is_dark_for_a_fresh_user(): void
    {
        $user = User::factory()->create();

        $this->assertSame('dark', $user->fresh()->theme);
    }

    public function test_authenticated_user_can_persist_a_valid_theme(): void
    {
        $user = User::factory()->create(['theme' => 'dark']);

        $this->actingAs($user)
            ->post(route('settings.theme.update'), ['theme' => 'light'])
            ->assertRedirect();

        $this->assertSame('light', $user->fresh()->theme);
    }

    public function test_each_supported_value_is_accepted(): void
    {
        $user = User::factory()->create();

        foreach (['dark', 'light', 'system'] as $value) {
            $this->actingAs($user)
                ->post(route('settings.theme.update'), ['theme' => $value])
                ->assertRedirect();
            $this->assertSame($value, $user->fresh()->theme);
        }
    }

    public function test_rejects_an_unknown_theme_value(): void
    {
        $user = User::factory()->create(['theme' => 'dark']);

        $this->actingAs($user)
            ->post(route('settings.theme.update'), ['theme' => 'sepia'])
            ->assertSessionHasErrors(['theme']);

        $this->assertSame('dark', $user->fresh()->theme, 'rejected payload must not mutate the column');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->post(route('settings.theme.update'), ['theme' => 'light'])
            ->assertRedirect(route('login'));
    }

    public function test_inertia_share_surfaces_the_theme_on_authenticated_pages(): void
    {
        // Spec 036 — AppLayout reads `auth.user.theme` on mount to
        // toggle the `<html>` class. The User model includes `theme`
        // in fillable, so the default toArray() shape exposes it.
        $user = User::factory()->create(['theme' => 'light']);

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertSuccessful()
            ->assertInertia(
                fn ($page) => $page
                    ->where('auth.user.theme', 'light'),
            );
    }
}
