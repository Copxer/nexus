<?php

namespace Tests\Feature\Security;

use App\Domain\GitHub\Actions\VerifyGitHubWebhookSignatureAction;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 039 — pin webhook signature handling: HMAC-SHA-256, timing-
 * safe compare, fails closed on missing secret / header, raw header
 * stored verbatim for forensic replay (not re-verified on read).
 *
 * The existing `VerifyGitHubWebhookSignatureActionTest` covers the
 * happy path + tampered bodies. This file locks the audit-trail
 * shape + the fails-closed surface so a refactor that "opens up"
 * missing-secret handling trips here.
 */
class WebhookSignatureAuditTest extends TestCase
{
    use RefreshDatabase;

    private function verify(): VerifyGitHubWebhookSignatureAction
    {
        return new VerifyGitHubWebhookSignatureAction;
    }

    public function test_missing_secret_fails_closed(): void
    {
        $body = json_encode(['action' => 'opened']);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'real-secret');

        $this->assertFalse(
            $this->verify()->execute($body, $signature, ''),
            'Empty secret config must never validate a webhook.',
        );
    }

    public function test_missing_signature_header_fails_closed(): void
    {
        $body = json_encode(['action' => 'opened']);

        $this->assertFalse($this->verify()->execute($body, null, 'real-secret'));
        $this->assertFalse($this->verify()->execute($body, '', 'real-secret'));
    }

    public function test_signature_verifies_round_trip_with_secret(): void
    {
        $body = json_encode(['action' => 'opened', 'number' => 42]);
        $secret = 'real-secret';
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret);

        $this->assertTrue($this->verify()->execute($body, $signature, $secret));
    }

    public function test_tampered_body_invalidates_signature(): void
    {
        $body = json_encode(['action' => 'opened']);
        $secret = 'real-secret';
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret);

        $tampered = json_encode(['action' => 'closed']);

        $this->assertFalse($this->verify()->execute($tampered, $signature, $secret));
    }

    public function test_signature_column_stores_raw_header_for_forensic_audit(): void
    {
        // The delivery row keeps the signature verbatim so an operator
        // can re-verify against the payload during an incident — the
        // verification action is the only gate at ingest time.
        $delivery = WebhookDelivery::factory()->create([
            'signature' => 'sha256=abcdef1234567890',
        ]);

        $this->assertSame('sha256=abcdef1234567890', $delivery->fresh()->signature);
    }
}
