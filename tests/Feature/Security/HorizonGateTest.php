<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Spec 039 — pin the Horizon dashboard gate's three-mode behavior:
 *
 *   - local/testing: any verified user passes.
 *   - production with empty allow-list: no one passes (fail closed).
 *   - production with allow-list: only matching emails pass.
 *
 * Catches a refactor that swaps the env check, drops the allow-list,
 * or grants access by default.
 */
class HorizonGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_env_allows_any_verified_user(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->assertTrue(Gate::forUser($user)->allows('viewHorizon'));
    }

    public function test_local_env_rejects_unverified_user(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $user = User::factory()->create(['email_verified_at' => null]);

        $this->assertFalse(Gate::forUser($user)->allows('viewHorizon'));
    }

    public function test_production_with_empty_allow_list_rejects_everyone(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['horizon.allow_list' => []]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->assertFalse(
            Gate::forUser($user)->allows('viewHorizon'),
            'Empty allow-list in production must fail closed.',
        );
    }

    public function test_production_allow_list_grants_only_matching_emails(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['horizon.allow_list' => ['ops@example.com', 'admin@example.com']]);

        $allowed = User::factory()->create([
            'email' => 'ops@example.com',
            'email_verified_at' => now(),
        ]);
        $rejected = User::factory()->create([
            'email' => 'random@example.com',
            'email_verified_at' => now(),
        ]);

        $this->assertTrue(Gate::forUser($allowed)->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser($rejected)->allows('viewHorizon'));
    }

    public function test_unauthenticated_user_is_rejected_in_every_env(): void
    {
        foreach (['local', 'testing', 'production'] as $env) {
            $this->app->detectEnvironment(fn () => $env);

            $this->assertFalse(
                Gate::allows('viewHorizon'),
                "Guest must be rejected in env={$env}.",
            );
        }
    }
}
