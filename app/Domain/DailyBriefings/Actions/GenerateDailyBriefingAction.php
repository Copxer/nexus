<?php

namespace App\Domain\DailyBriefings\Actions;

use App\Domain\AI\Contracts\LlmClient;
use App\Domain\AI\DataTransferObjects\LlmPrompt;
use App\Enums\DailyBriefingStatus;
use App\Models\DailyBriefing;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

class GenerateDailyBriefingAction
{
    public const PROMPT_VERSION = 'daily-briefing-v1';

    public function __construct(private readonly LlmClient $llm) {}

    /**
     * @param  array<string, mixed>  $inputSnapshot
     */
    public function execute(User $user, array $inputSnapshot): DailyBriefing
    {
        $briefingDate = $this->briefingDate($inputSnapshot);

        if (! config('services.llm.enabled', false)) {
            return $this->markFailed($user, $briefingDate, $inputSnapshot, 'AI features are disabled.');
        }

        try {
            $response = $this->llm->complete($this->prompt($inputSnapshot));
            $output = $this->validatedOutput($response->text);

            return $this->writeBriefing($user, $briefingDate, [
                'status' => DailyBriefingStatus::Generated,
                'input_snapshot' => $inputSnapshot,
                'summary' => $output['summary'],
                'highlights' => $output['highlights'],
                'risks' => $output['risks'],
                'prompt_version' => self::PROMPT_VERSION,
                'generated_at' => now(),
                'delivered_at' => null,
                'error_message' => null,
            ]);
        } catch (Throwable $exception) {
            return $this->markFailed($user, $briefingDate, $inputSnapshot, $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $inputSnapshot */
    private function prompt(array $inputSnapshot): LlmPrompt
    {
        return new LlmPrompt(
            version: self::PROMPT_VERSION,
            system: 'You write concise operator briefings from structured Nexus monitoring data. Return only valid JSON matching the requested schema. Do not invent facts. Do not include secrets, raw logs, tokens, webhook URLs, or unsupported claims.',
            user: json_encode([
                'prompt_version' => self::PROMPT_VERSION,
                'task' => 'Create a daily operator briefing from this bounded input snapshot.',
                'schema' => [
                    'summary' => 'string, 2-4 concise paragraphs',
                    'highlights' => 'array of 3-6 short strings',
                    'risks' => 'array of 0-5 short strings tied to concrete source entities',
                    'next_steps' => 'optional array of short strings when supported by data',
                ],
                'input_snapshot' => $inputSnapshot,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return array{summary: string, highlights: array<int, string>, risks: array<int, string>}
     */
    private function validatedOutput(string $text): array
    {
        $payload = json_decode($this->stripCodeFence($text), true);

        if (! is_array($payload)) {
            throw new \UnexpectedValueException('LLM response was not valid JSON.');
        }

        $summary = $this->sanitizeString($payload['summary'] ?? null, 4_000);
        $highlights = $this->sanitizeList($payload['highlights'] ?? null, 6);
        $risks = $this->sanitizeList($payload['risks'] ?? [], 5);

        if ($summary === '') {
            throw new \UnexpectedValueException('LLM response summary is required.');
        }

        if (count($highlights) < 3 || count($highlights) > 6) {
            throw new \UnexpectedValueException('LLM response must include 3 to 6 highlights.');
        }

        if (count($risks) > 5) {
            throw new \UnexpectedValueException('LLM response must include at most 5 risks.');
        }

        return [
            'summary' => $summary,
            'highlights' => $highlights,
            'risks' => $risks,
        ];
    }

    /** @param mixed $value */
    private function sanitizeString($value, int $limit): string
    {
        if (! is_string($value)) {
            return '';
        }

        $value = trim(strip_tags(preg_replace('/[[:cntrl:]]+/', ' ', $value) ?? ''));

        return Str::limit($value, $limit, '');
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function sanitizeList($value, int $limit): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn ($item): string => $this->sanitizeString($item, 500))
            ->filter(fn (string $item): bool => $item !== '')
            ->take($limit)
            ->values()
            ->all();
    }

    private function stripCodeFence(string $text): string
    {
        $text = trim($text);

        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        }

        return trim($text);
    }

    /** @param array<string, mixed> $inputSnapshot */
    private function briefingDate(array $inputSnapshot): CarbonImmutable
    {
        $date = $inputSnapshot['window']['briefing_date'] ?? null;

        if (! is_string($date) || $date === '') {
            throw new \InvalidArgumentException('Input snapshot is missing window.briefing_date.');
        }

        return CarbonImmutable::parse($date)->startOfDay();
    }

    /** @param array<string, mixed> $inputSnapshot */
    private function markFailed(User $user, CarbonImmutable $briefingDate, array $inputSnapshot, string $error): DailyBriefing
    {
        return $this->writeBriefing($user, $briefingDate, [
            'status' => DailyBriefingStatus::Failed,
            'input_snapshot' => $inputSnapshot,
            'summary' => null,
            'highlights' => null,
            'risks' => null,
            'prompt_version' => self::PROMPT_VERSION,
            'generated_at' => null,
            'delivered_at' => null,
            'error_message' => Str::limit($error, 2_000, ''),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function writeBriefing(User $user, CarbonImmutable $briefingDate, array $attributes): DailyBriefing
    {
        $briefing = DailyBriefing::query()
            ->where('user_id', $user->id)
            ->whereDate('briefing_date', $briefingDate->toDateString())
            ->first() ?? new DailyBriefing([
                'user_id' => $user->id,
                'briefing_date' => $briefingDate->toDateString(),
            ]);

        $briefing->fill($attributes);
        $briefing->save();

        return $briefing;
    }
}
