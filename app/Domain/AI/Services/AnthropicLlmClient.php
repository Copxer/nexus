<?php

namespace App\Domain\AI\Services;

use App\Domain\AI\Contracts\LlmClient;
use App\Domain\AI\DataTransferObjects\LlmPrompt;
use App\Domain\AI\DataTransferObjects\LlmResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnthropicLlmClient implements LlmClient
{
    public function complete(LlmPrompt $prompt): LlmResponse
    {
        $apiKey = (string) config('services.llm.api_key', '');
        $model = (string) config('services.llm.model', 'claude-3-5-haiku-latest');
        $timeout = (int) config('services.llm.timeout', 20);

        if ($apiKey === '') {
            throw new RuntimeException('LLM API key is not configured.');
        }

        $response = Http::withHeaders([
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
            'x-api-key' => $apiKey,
        ])
            ->timeout($timeout)
            ->retry(1, 250, function ($exception, $request): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                if (! $exception instanceof RequestException) {
                    return false;
                }

                return in_array($exception->response->status(), [429, 500, 502, 503, 504], true);
            }, throw: true)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => $prompt->maxTokens,
                'system' => $prompt->system,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt->user],
                ],
            ])
            ->throw()
            ->json();

        $text = collect($response['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n");

        if ($text === '') {
            throw new RuntimeException('LLM response did not include text content.');
        }

        return new LlmResponse(
            text: $text,
            model: $response['model'] ?? $model,
            metadata: ['usage' => $response['usage'] ?? []],
        );
    }
}
