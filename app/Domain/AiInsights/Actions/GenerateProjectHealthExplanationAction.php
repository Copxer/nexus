<?php

namespace App\Domain\AiInsights\Actions;

use App\Domain\AI\Contracts\LlmClient;
use App\Domain\AI\DataTransferObjects\LlmPrompt;
use App\Enums\HealthScoreBand;
use App\Enums\ProjectHealthExplanationStatus;
use App\Models\Project;
use App\Models\ProjectHealthExplanation;
use Illuminate\Support\Str;
use Throwable;

class GenerateProjectHealthExplanationAction
{
    public const PROMPT_VERSION = 'project-health-explanation-v1';

    public function __construct(private readonly LlmClient $llm) {}

    /** @param array<string, mixed> $inputSnapshot */
    public function execute(Project $project, array $inputSnapshot): ProjectHealthExplanation
    {
        if (! config('services.llm.enabled', false)) {
            return $this->markFailed($project, $inputSnapshot, 'AI features are disabled.');
        }

        try {
            $response = $this->llm->complete($this->prompt($inputSnapshot));
            $output = $this->validatedOutput($response->text);

            return $this->writeExplanation($project, [
                'status' => ProjectHealthExplanationStatus::Explained,
                'health_score' => $this->healthScore($project, $inputSnapshot),
                'health_band' => $this->healthBand($project, $inputSnapshot),
                'summary' => $output['summary'],
                'drivers' => $output['drivers'],
                'recommended_actions' => $output['recommended_actions'],
                'input_snapshot' => $inputSnapshot,
                'prompt_version' => self::PROMPT_VERSION,
                'model' => $response->model,
                'explained_at' => now(),
                'failed_at' => null,
                'error_message' => null,
            ]);
        } catch (Throwable $exception) {
            return $this->markFailed($project, $inputSnapshot, $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $inputSnapshot */
    private function prompt(array $inputSnapshot): LlmPrompt
    {
        return new LlmPrompt(
            version: self::PROMPT_VERSION,
            system: 'You explain an existing Nexus project health score from structured monitoring data. Return only valid JSON matching the requested schema. Do not invent a different score. Do not include secrets, raw logs, tokens, webhook URLs, or unsupported claims.',
            user: json_encode([
                'prompt_version' => self::PROMPT_VERSION,
                'task' => 'Explain why this existing project health score has its current value.',
                'schema' => [
                    'summary' => 'string, concise operator-facing explanation',
                    'drivers' => 'array of 1-6 short strings tied to concrete input_snapshot drivers',
                    'recommended_actions' => 'array of 0-4 short strings',
                ],
                'input_snapshot' => $inputSnapshot,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return array{summary: string, drivers: array<int, string>, recommended_actions: array<int, string>}
     */
    private function validatedOutput(string $text): array
    {
        $payload = json_decode($this->stripCodeFence($text), true);

        if (! is_array($payload)) {
            throw new \UnexpectedValueException('LLM response was not valid JSON.');
        }

        $summary = $this->sanitizeString($payload['summary'] ?? null, 1_000);
        $drivers = $this->sanitizeList($payload['drivers'] ?? null, 6);
        $recommendedActions = $this->sanitizeList($payload['recommended_actions'] ?? [], 4);

        if ($summary === '') {
            throw new \UnexpectedValueException('LLM response summary is required.');
        }

        if (count($drivers) < 1 || count($drivers) > 6) {
            throw new \UnexpectedValueException('LLM response must include 1 to 6 drivers.');
        }

        return [
            'summary' => $summary,
            'drivers' => $drivers,
            'recommended_actions' => $recommendedActions,
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
    private function markFailed(Project $project, array $inputSnapshot, string $error): ProjectHealthExplanation
    {
        return $this->writeExplanation($project, [
            'status' => ProjectHealthExplanationStatus::Failed,
            'health_score' => $this->healthScore($project, $inputSnapshot),
            'health_band' => $this->healthBand($project, $inputSnapshot),
            'summary' => null,
            'drivers' => [],
            'recommended_actions' => [],
            'input_snapshot' => $inputSnapshot,
            'prompt_version' => self::PROMPT_VERSION,
            'model' => null,
            'explained_at' => null,
            'failed_at' => now(),
            'error_message' => Str::limit($error, 2_000, ''),
        ]);
    }

    /** @param array<string, mixed> $inputSnapshot */
    private function healthScore(Project $project, array $inputSnapshot): int
    {
        $score = $inputSnapshot['project']['health_score'] ?? $project->health_score ?? 0;

        return max(0, min(100, (int) $score));
    }

    /** @param array<string, mixed> $inputSnapshot */
    private function healthBand(Project $project, array $inputSnapshot): HealthScoreBand
    {
        $band = $inputSnapshot['project']['health_band'] ?? null;

        if (is_string($band) && ($healthBand = HealthScoreBand::tryFrom($band)) !== null) {
            return $healthBand;
        }

        return HealthScoreBand::fromScore($this->healthScore($project, $inputSnapshot));
    }

    /** @param array<string, mixed> $attributes */
    private function writeExplanation(Project $project, array $attributes): ProjectHealthExplanation
    {
        $explanation = ProjectHealthExplanation::query()
            ->where('project_id', $project->id)
            ->first() ?? new ProjectHealthExplanation(['project_id' => $project->id]);

        $explanation->fill($attributes);
        $explanation->save();

        return $explanation;
    }
}
