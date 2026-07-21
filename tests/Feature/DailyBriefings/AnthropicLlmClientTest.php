<?php

namespace Tests\Feature\DailyBriefings;

use App\Domain\AI\Contracts\LlmClient;
use App\Domain\AI\DataTransferObjects\LlmPrompt;
use App\Domain\AI\Services\AnthropicLlmClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicLlmClientTest extends TestCase
{
    public function test_container_binds_llm_client_to_anthropic_provider(): void
    {
        config(['services.llm.provider' => 'anthropic']);

        $this->assertInstanceOf(AnthropicLlmClient::class, app(LlmClient::class));
    }

    public function test_anthropic_client_sends_configured_message_request_and_returns_text_response(): void
    {
        config([
            'services.llm.api_key' => 'test-key',
            'services.llm.model' => 'claude-test-model',
            'services.llm.timeout' => 7,
        ]);

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'model' => 'claude-test-model',
                'content' => [
                    ['type' => 'text', 'text' => '{"summary":"ok"}'],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $response = app(AnthropicLlmClient::class)->complete(new LlmPrompt(
            version: 'daily-briefing-v1',
            system: 'System instructions',
            user: 'User prompt',
            maxTokens: 321,
        ));

        $this->assertSame('{"summary":"ok"}', $response->text);
        $this->assertSame('claude-test-model', $response->model);
        $this->assertSame(['input_tokens' => 10, 'output_tokens' => 5], $response->metadata['usage']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key', 'test-key')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && $request['model'] === 'claude-test-model'
                && $request['max_tokens'] === 321
                && $request['system'] === 'System instructions'
                && $request['messages'] === [['role' => 'user', 'content' => 'User prompt']];
        });
    }

    public function test_anthropic_client_retries_once_for_transient_provider_errors(): void
    {
        config([
            'services.llm.api_key' => 'test-key',
            'services.llm.model' => 'claude-test-model',
        ]);

        Http::fakeSequence('api.anthropic.com/v1/messages')
            ->push(['error' => ['message' => 'rate limited']], 429)
            ->push([
                'model' => 'claude-test-model',
                'content' => [
                    ['type' => 'text', 'text' => '{"summary":"retried"}'],
                ],
            ], 200);

        $response = app(AnthropicLlmClient::class)->complete(new LlmPrompt(
            version: 'daily-briefing-v1',
            system: 'System instructions',
            user: 'User prompt',
        ));

        $this->assertSame('{"summary":"retried"}', $response->text);
        Http::assertSentCount(2);
    }
}
