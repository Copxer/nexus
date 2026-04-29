<?php

namespace Tests\Feature\GitHub\Webhooks;

use App\Domain\GitHub\Jobs\ProcessGitHubWebhookJob;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GitHubWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.github.webhook_secret' => self::SECRET]);
    }

    private function signedPost(string $body, array $headers): TestResponse
    {
        return $this->call(
            'POST',
            route('webhooks.github'),
            [],
            [],
            [],
            $this->serverHeaders($headers),
            $body,
        );
    }

    /** Convert simple header keys to Symfony's `HTTP_*` form. */
    private function serverHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $key = 'HTTP_'.strtoupper(str_replace('-', '_', $name));
            $out[$key] = $value;
        }
        $out['CONTENT_TYPE'] = 'application/json';

        return $out;
    }

    private function sign(string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $body, self::SECRET);
    }

    public function test_invalid_signature_returns_401_and_does_not_insert_a_row(): void
    {
        Queue::fake();
        $body = json_encode(['action' => 'opened']);

        $response = $this->signedPost($body, [
            'X-GitHub-Event' => 'issues',
            'X-GitHub-Delivery' => 'delivery-1',
            'X-Hub-Signature-256' => 'sha256=deadbeef',
        ]);

        $response->assertStatus(401);
        // Don't echo anything to a request we couldn't authenticate.
        $this->assertSame('', $response->getContent());
        $this->assertSame(0, WebhookDelivery::query()->count());
        Queue::assertNotPushed(ProcessGitHubWebhookJob::class);
    }

    public function test_missing_signature_header_is_rejected(): void
    {
        $body = json_encode(['action' => 'opened']);

        $this->signedPost($body, [
            'X-GitHub-Event' => 'issues',
            'X-GitHub-Delivery' => 'delivery-1',
        ])->assertStatus(401);

        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    public function test_valid_signature_inserts_row_and_dispatches_job(): void
    {
        Queue::fake();
        $body = json_encode([
            'action' => 'opened',
            'repository' => ['full_name' => 'octocat/hello-world'],
            'issue' => ['id' => 1, 'number' => 1, 'title' => 'Bug'],
        ]);

        $this->signedPost($body, [
            'X-GitHub-Event' => 'issues',
            'X-GitHub-Delivery' => 'delivery-A',
            'X-Hub-Signature-256' => $this->sign($body),
        ])->assertStatus(200);

        $this->assertSame(1, WebhookDelivery::query()->count());
        $this->assertDatabaseHas('github_webhook_deliveries', [
            'github_delivery_id' => 'delivery-A',
            'event' => 'issues',
            'action' => 'opened',
            'repository_full_name' => 'octocat/hello-world',
            'status' => 'received',
        ]);
        Queue::assertPushed(ProcessGitHubWebhookJob::class, 1);
    }

    public function test_duplicate_delivery_id_is_idempotent(): void
    {
        Queue::fake();
        $body = json_encode([
            'action' => 'opened',
            'repository' => ['full_name' => 'octocat/hello-world'],
        ]);
        $headers = [
            'X-GitHub-Event' => 'issues',
            'X-GitHub-Delivery' => 'delivery-DUP',
            'X-Hub-Signature-256' => $this->sign($body),
        ];

        $this->signedPost($body, $headers)->assertStatus(200);
        $this->signedPost($body, $headers)->assertStatus(200);

        $this->assertSame(1, WebhookDelivery::query()->count());
        Queue::assertPushed(ProcessGitHubWebhookJob::class, 1);
    }

    public function test_missing_event_or_delivery_header_is_rejected(): void
    {
        $body = json_encode(['action' => 'opened']);

        $this->signedPost($body, [
            'X-GitHub-Event' => 'issues',
            'X-Hub-Signature-256' => $this->sign($body),
        ])->assertStatus(400);

        $this->signedPost($body, [
            'X-GitHub-Delivery' => 'delivery-X',
            'X-Hub-Signature-256' => $this->sign($body),
        ])->assertStatus(400);

        $this->assertSame(0, WebhookDelivery::query()->count());
    }
}
