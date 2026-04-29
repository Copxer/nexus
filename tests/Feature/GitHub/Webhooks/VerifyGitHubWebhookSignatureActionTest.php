<?php

namespace Tests\Feature\GitHub\Webhooks;

use App\Domain\GitHub\Actions\VerifyGitHubWebhookSignatureAction;
use Tests\TestCase;

class VerifyGitHubWebhookSignatureActionTest extends TestCase
{
    private VerifyGitHubWebhookSignatureAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new VerifyGitHubWebhookSignatureAction;
    }

    public function test_returns_true_for_a_correctly_signed_body(): void
    {
        $body = '{"action":"opened"}';
        $secret = 'whsec_dev_secret';
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret);

        $this->assertTrue($this->action->execute($body, $signature, $secret));
    }

    public function test_returns_false_for_a_tampered_body(): void
    {
        $secret = 'whsec_dev_secret';
        $signature = 'sha256='.hash_hmac('sha256', '{"action":"opened"}', $secret);

        // Same secret + signature, different body → must reject.
        $this->assertFalse(
            $this->action->execute('{"action":"closed"}', $signature, $secret),
        );
    }

    public function test_returns_false_when_secret_is_empty(): void
    {
        $body = '{"action":"opened"}';
        $signature = 'sha256='.hash_hmac('sha256', $body, '');

        // Even if the math checks out, an empty secret means the app
        // isn't configured — fail closed.
        $this->assertFalse($this->action->execute($body, $signature, ''));
    }

    public function test_returns_false_when_signature_header_is_missing(): void
    {
        $this->assertFalse(
            $this->action->execute('{"action":"opened"}', null, 'whsec_dev_secret'),
        );
    }

    public function test_returns_false_when_signature_header_is_empty(): void
    {
        $this->assertFalse(
            $this->action->execute('{"action":"opened"}', '', 'whsec_dev_secret'),
        );
    }
}
