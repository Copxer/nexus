<?php

namespace App\Domain\AiInsights\Actions;

use App\Domain\AI\Contracts\LlmClient;
use App\Domain\AI\DataTransferObjects\LlmPrompt;
use App\Domain\Alerts\Actions\TriggerAlertAction;
use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\PullRequestRiskAssessmentStatus;
use App\Enums\PullRequestRiskLevel;
use App\Models\Alert;
use App\Models\GithubPullRequest;
use App\Models\PullRequestRiskAssessment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GeneratePullRequestRiskAssessmentAction
{
    public const PROMPT_VERSION = 'pr-risk-v1';

    public function __construct(
        private readonly LlmClient $llm,
        private readonly TriggerAlertAction $triggerAlert,
    ) {}

    /** @param array<string, mixed> $inputSnapshot */
    public function execute(GithubPullRequest $pullRequest, array $inputSnapshot): PullRequestRiskAssessment
    {
        if (! config('services.llm.enabled', false)) {
            return $this->markFailed($pullRequest, $inputSnapshot, 'AI features are disabled.');
        }

        try {
            $response = $this->llm->complete($this->prompt($inputSnapshot));
            $output = $this->validatedOutput($response->text);

            $previousRiskLevel = $this->currentRiskLevel($pullRequest);

            $assessment = $this->writeAssessment($pullRequest, [
                'status' => PullRequestRiskAssessmentStatus::Scored,
                'risk_level' => $output['risk_level'],
                'risk_score' => $output['risk_score'],
                'summary' => $output['summary'],
                'reasons' => $output['reasons'],
                'recommended_actions' => $output['recommended_actions'],
                'input_snapshot' => $inputSnapshot,
                'prompt_version' => self::PROMPT_VERSION,
                'model' => $response->model,
                'assessed_at' => now(),
                'failed_at' => null,
                'error_message' => null,
            ]);

            $this->notifyForMaterialRiskIncrease($pullRequest, $previousRiskLevel, $assessment);

            return $assessment;
        } catch (Throwable $exception) {
            return $this->markFailed($pullRequest, $inputSnapshot, $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $inputSnapshot */
    private function prompt(array $inputSnapshot): LlmPrompt
    {
        return new LlmPrompt(
            version: self::PROMPT_VERSION,
            system: 'You assess pull request review risk from structured Nexus metadata. Return only valid JSON matching the requested schema. Do not invent facts. Do not include secrets, raw logs, tokens, webhook URLs, raw diffs, or unsupported claims.',
            user: json_encode([
                'prompt_version' => self::PROMPT_VERSION,
                'task' => 'Assess this pull request risk for an operator deciding review priority.',
                'schema' => [
                    'risk_level' => 'one of: low, medium, high, critical',
                    'risk_score' => 'integer 0-100',
                    'summary' => 'string, 1-2 short sentences',
                    'reasons' => 'array of 1-5 short strings tied to concrete input_snapshot fields',
                    'recommended_actions' => 'array of 0-4 short strings',
                ],
                'input_snapshot' => $inputSnapshot,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return array{risk_level: PullRequestRiskLevel, risk_score: int, summary: string, reasons: array<int, string>, recommended_actions: array<int, string>}
     */
    private function validatedOutput(string $text): array
    {
        $payload = json_decode($this->stripCodeFence($text), true);

        if (! is_array($payload)) {
            throw new \UnexpectedValueException('LLM response was not valid JSON.');
        }

        $riskLevel = PullRequestRiskLevel::tryFrom((string) ($payload['risk_level'] ?? ''));
        $riskScore = $payload['risk_score'] ?? null;
        $summary = $this->sanitizeString($payload['summary'] ?? null, 1_000);
        $reasons = $this->sanitizeList($payload['reasons'] ?? null, 5);
        $recommendedActions = $this->sanitizeList($payload['recommended_actions'] ?? [], 4);

        if ($riskLevel === null) {
            throw new \UnexpectedValueException('LLM response risk_level is invalid.');
        }

        if (! is_int($riskScore) || $riskScore < 0 || $riskScore > 100) {
            throw new \UnexpectedValueException('LLM response risk_score must be an integer from 0 to 100.');
        }

        if ($summary === '') {
            throw new \UnexpectedValueException('LLM response summary is required.');
        }

        if (count($reasons) < 1 || count($reasons) > 5) {
            throw new \UnexpectedValueException('LLM response must include 1 to 5 reasons.');
        }

        return [
            'risk_level' => $riskLevel,
            'risk_score' => $riskScore,
            'summary' => $summary,
            'reasons' => $reasons,
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

    private function currentRiskLevel(GithubPullRequest $pullRequest): ?PullRequestRiskLevel
    {
        $riskLevel = PullRequestRiskAssessment::query()
            ->where('github_pull_request_id', $pullRequest->id)
            ->value('risk_level');

        return is_string($riskLevel) ? PullRequestRiskLevel::tryFrom($riskLevel) : null;
    }

    private function notifyForMaterialRiskIncrease(
        GithubPullRequest $pullRequest,
        ?PullRequestRiskLevel $previousRiskLevel,
        PullRequestRiskAssessment $assessment,
    ): void {
        $riskLevel = $assessment->risk_level;

        if ($riskLevel === null || ! in_array($riskLevel, [PullRequestRiskLevel::High, PullRequestRiskLevel::Critical], true)) {
            return;
        }

        if ($previousRiskLevel !== null && $this->riskRank($riskLevel) <= $this->riskRank($previousRiskLevel)) {
            return;
        }

        $type = $this->notificationAlertType($riskLevel);

        if (Alert::query()
            ->where('source', AlertSource::Github->value)
            ->where('source_id', $pullRequest->id)
            ->where('type', $type)
            ->exists()) {
            return;
        }

        try {
            $pullRequest->loadMissing('repository.project');
            $repository = $pullRequest->repository;
            $project = $repository?->project;

            if ($project === null) {
                return;
            }

            $this->triggerAlert->execute([
                'project_id' => $project->id,
                'source' => AlertSource::Github,
                'source_id' => $pullRequest->id,
                'type' => $type,
                'severity' => $riskLevel === PullRequestRiskLevel::Critical ? AlertSeverity::Critical : AlertSeverity::Warning,
                'title' => sprintf('PR #%d risk is %s', $pullRequest->number, $riskLevel->value),
                'description' => $assessment->summary,
                'metadata' => [
                    'repository' => $repository?->full_name,
                    'pull_request_id' => $pullRequest->id,
                    'pull_request_number' => $pullRequest->number,
                    'risk_level' => $riskLevel->value,
                    'risk_score' => $assessment->risk_score,
                    'assessment_id' => $assessment->id,
                ],
            ]);
        } catch (Throwable $exception) {
            Log::warning('PR risk notification dispatch failed', [
                'pull_request_id' => $pullRequest->id,
                'assessment_id' => $assessment->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function riskRank(PullRequestRiskLevel $riskLevel): int
    {
        return match ($riskLevel) {
            PullRequestRiskLevel::Low => 1,
            PullRequestRiskLevel::Medium => 2,
            PullRequestRiskLevel::High => 3,
            PullRequestRiskLevel::Critical => 4,
        };
    }

    private function notificationAlertType(PullRequestRiskLevel $riskLevel): string
    {
        return 'pull_request.risk.'.$riskLevel->value;
    }

    /** @param array<string, mixed> $inputSnapshot */
    private function markFailed(GithubPullRequest $pullRequest, array $inputSnapshot, string $error): PullRequestRiskAssessment
    {
        return $this->writeAssessment($pullRequest, [
            'status' => PullRequestRiskAssessmentStatus::Failed,
            'risk_level' => null,
            'risk_score' => null,
            'summary' => null,
            'reasons' => [],
            'recommended_actions' => [],
            'input_snapshot' => $inputSnapshot,
            'prompt_version' => self::PROMPT_VERSION,
            'model' => null,
            'assessed_at' => null,
            'failed_at' => now(),
            'error_message' => Str::limit($error, 2_000, ''),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function writeAssessment(GithubPullRequest $pullRequest, array $attributes): PullRequestRiskAssessment
    {
        $assessment = PullRequestRiskAssessment::query()
            ->where('github_pull_request_id', $pullRequest->id)
            ->first() ?? new PullRequestRiskAssessment(['github_pull_request_id' => $pullRequest->id]);

        $assessment->fill($attributes);
        $assessment->save();

        return $assessment;
    }
}
