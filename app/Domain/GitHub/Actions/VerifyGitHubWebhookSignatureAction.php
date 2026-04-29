<?php

namespace App\Domain\GitHub\Actions;

/**
 * Verify a GitHub webhook's `X-Hub-Signature-256` header against the
 * raw request body using a shared secret + HMAC-SHA-256.
 *
 *   GitHub sends:  X-Hub-Signature-256: sha256=<hex digest>
 *   We compute:    sha256=hash_hmac('sha256', $rawBody, $secret)
 *   Compare:       hash_equals(...) — timing-safe.
 *
 * Caller is the webhook controller. On `false` the controller MUST
 * return 401 with no DB write — that's the only thing standing between
 * an unauthenticated POST and a row in `github_webhook_deliveries`.
 *
 * The shared secret lives in `config('services.github.webhook_secret')`.
 * If the secret is missing/empty we fail closed (`false`) — there's no
 * "open" mode where unsigned requests are accepted.
 */
class VerifyGitHubWebhookSignatureAction
{
    public function execute(string $rawBody, ?string $signatureHeader, string $secret): bool
    {
        if ($secret === '' || $signatureHeader === null || $signatureHeader === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }
}
