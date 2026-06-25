<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Spec 039 — pin password handling: bcrypt at rest, hidden from
 * serialization, never present in Inertia's shared `auth.user`
 * prop.
 */
class UserPasswordSecretTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_is_bcrypt_hashed_at_rest(): void
    {
        $user = User::factory()->create([
            'password' => 'plaintext-secret',
        ]);

        $raw = DB::table('users')->where('id', $user->id)->first();

        $this->assertNotSame('plaintext-secret', $raw->password);
        $this->assertTrue(
            Hash::check('plaintext-secret', $raw->password),
            'Stored password must verify against the original plaintext.',
        );
    }

    public function test_password_and_remember_token_are_hidden_from_array(): void
    {
        $user = User::factory()->create()->fresh();
        $user->remember_token = 'rem_xxx';
        $user->save();

        $serialized = $user->fresh()->toArray();

        $this->assertArrayNotHasKey('password', $serialized);
        $this->assertArrayNotHasKey('remember_token', $serialized);
    }

    public function test_auth_user_inertia_prop_does_not_expose_secrets(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => 'plaintext-secret',
        ]);

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertSuccessful();

        // Inertia's data-page attribute carries the page JSON; any
        // password leakage would surface there. Grep the response
        // body for the plaintext + the bcrypt prefix.
        $body = $this->actingAs($user)->get(route('settings.index'))->getContent();
        $this->assertStringNotContainsString('plaintext-secret', $body);
        $this->assertStringNotContainsString('$2y$', $body, 'Bcrypt prefix in HTML means the hash leaked.');
    }
}
