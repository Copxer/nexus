<?php

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\Jobs\DispatchAlertNotificationJob;
use App\Models\Alert;
use App\Models\AlertNotificationChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 042 — outbound webhook signing. When the channel's config
 * carries a `signing_secret`, the driver attaches an
 * `X-Nexus-Signature: sha256=<hmac>` header computed against the
 * raw JSON body. This mirrors the inbound GitHub webhook signature
 * shape so operators can reuse familiar tooling on the receiving end.
 */
class WebhookSignatureOutboundTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbound_webhook_carries_hmac_sha256_signature_when_secret_is_set(): void
    {
        $secret = 'super-secret';
        Http::fake([
            'ops.example.com/*' => Http::response('', 202),
        ]);

        $user = User::factory()->create();
        $channel = AlertNotificationChannel::factory()
            ->webhook('https://ops.example.com/hook', $secret)
            ->for($user)
            ->create();
        $alert = Alert::factory()->create();

        (new DispatchAlertNotificationJob($alert->id, $channel->id))->handle();

        Http::assertSent(function ($request) use ($secret): bool {
            $signatureHeader = $request->header('X-Nexus-Signature')[0] ?? null;

            if ($signatureHeader === null) {
                return false;
            }

            if (! str_starts_with($signatureHeader, 'sha256=')) {
                return false;
            }

            $expected = 'sha256='.hash_hmac('sha256', $request->body(), $secret);

            return hash_equals($expected, $signatureHeader);
        });
    }

    public function test_outbound_webhook_carries_no_signature_when_no_secret(): void
    {
        Http::fake([
            'ops.example.com/*' => Http::response('', 202),
        ]);

        $user = User::factory()->create();
        $channel = AlertNotificationChannel::factory()
            ->webhook('https://ops.example.com/hook')
            ->for($user)
            ->create();
        $alert = Alert::factory()->create();

        (new DispatchAlertNotificationJob($alert->id, $channel->id))->handle();

        Http::assertSent(fn ($req) => ! $req->hasHeader('X-Nexus-Signature'));
    }
}
