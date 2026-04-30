<?php

namespace Tests\Feature\Mail;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_verify_email_uses_nexus_brand_chrome(): void
    {
        $user = User::factory()->create();

        $html = (new VerifyEmail)->toMail($user)->render();

        $this->assertStringContainsString('background-color: #020617', $html, 'app-base background should be applied to body / wrapper');
        $this->assertStringContainsString('background-color: #0f172a', $html, 'inner card should use the slate panel surface');
        $this->assertStringContainsString('color: #f8fafc', $html, 'headings should use the primary text color');
        $this->assertStringContainsString('background-color: #22d3ee', $html, 'primary CTA button should be cyan');
        $this->assertStringContainsString('nexus-logo.png', $html, 'header should embed the Nexus wordmark');
        $this->assertStringContainsString("'Inter'", $html, 'Inter should be the first font in the stack');
    }

    public function test_password_reset_uses_nexus_brand_chrome(): void
    {
        $user = User::factory()->create();

        $html = (new ResetPassword('fake-token'))->toMail($user)->render();

        $this->assertStringContainsString('background-color: #020617', $html);
        $this->assertStringContainsString('background-color: #22d3ee', $html);
        $this->assertStringContainsString('nexus-logo.png', $html);
    }
}
