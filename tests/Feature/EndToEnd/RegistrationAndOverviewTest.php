<?php

namespace Tests\Feature\EndToEnd;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Spec 040 — end-to-end: guest registers, lands unverified,
 * clicks the signed verification link, arrives on `/overview`.
 *
 * Pins the contract that:
 *   - `POST /register` writes the user + auto-authenticates.
 *   - Unverified users get bounced from `/overview` to
 *     `/verify-email`.
 *   - The signed verification link flips `email_verified_at`
 *     and dispatches the `Verified` event.
 *   - Verified user lands on `/overview` with a 200.
 *
 * A regression at any step of this chain breaks the whole
 * onboarding flow — this test fails loudly instead of waiting
 * for a manual smoke pass to notice.
 */
class RegistrationAndOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_registration_through_overview_landing(): void
    {
        Event::fake([Verified::class]);

        // 1. Guest registers.
        $this->post('/register', [
            'name' => 'Spec 040 user',
            'email' => 'spec040@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('overview', absolute: false));

        $this->assertAuthenticated();

        $user = User::query()
            ->where('email', 'spec040@example.com')
            ->firstOrFail();
        $this->assertNull(
            $user->email_verified_at,
            'Fresh registration must not auto-verify the email.',
        );

        // 2. Unverified user can't see overview — Laravel's
        //    `verified` middleware bounces to the notice page.
        $this->actingAs($user)
            ->get(route('overview'))
            ->assertRedirect(route('verification.notice'));

        // 3. The signed verification link flips the column +
        //    fires the Verified event.
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        // The default `VerifyEmailController` redirects to the
        // intended URL or `/overview` — assert by status code +
        // is-redirect, not exact URL, so a future query-param
        // addition (`?verified=1`) doesn't trip the test.
        $response = $this->actingAs($user)->get($verificationUrl);
        $response->assertStatus(302);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class);

        // 4. Verified user lands on `/overview` with 200.
        $this->actingAs($user->fresh())
            ->get(route('overview'))
            ->assertSuccessful();
    }
}
