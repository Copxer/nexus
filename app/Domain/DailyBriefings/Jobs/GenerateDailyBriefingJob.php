<?php

namespace App\Domain\DailyBriefings\Jobs;

use App\Domain\DailyBriefings\Actions\GenerateDailyBriefingAction;
use App\Domain\DailyBriefings\Queries\GetDailyBriefingInputQuery;
use App\Enums\DailyBriefingStatus;
use App\Models\DailyBriefing;
use App\Models\DailyBriefingPreference;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class GenerateDailyBriefingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $userId,
        public readonly string $briefingDate,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->userId}:{$this->briefingDate}";
    }

    public function handle(GetDailyBriefingInputQuery $inputQuery, GenerateDailyBriefingAction $generate): void
    {
        if (! config('services.llm.enabled', false)) {
            return;
        }

        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        $preference = DailyBriefingPreference::query()
            ->where('user_id', $user->id)
            ->where('enabled', true)
            ->first();

        if ($preference === null) {
            return;
        }

        if ($preference->last_sent_for_date?->toDateString() === $this->briefingDate) {
            return;
        }

        $existing = $this->existingBriefing($user->id);

        if ($existing?->status === DailyBriefingStatus::Delivered) {
            return;
        }

        if ($existing?->status === DailyBriefingStatus::Generated || ($existing?->status === DailyBriefingStatus::Failed && $existing->summary !== null)) {
            SendDailyBriefingJob::dispatch($existing->id);

            return;
        }

        $snapshot = $inputQuery->execute(
            $user,
            $this->briefingDate,
            $preference->timezone,
            $preference->include_projects,
        );

        $briefing = $generate->execute($user, $snapshot);

        if ($briefing->status === DailyBriefingStatus::Generated) {
            SendDailyBriefingJob::dispatch($briefing->id);
        }
    }

    public function failed(Throwable $exception): void
    {
        $briefing = $this->existingBriefing($this->userId);

        if ($briefing === null || $briefing->status !== DailyBriefingStatus::Pending) {
            return;
        }

        $briefing->forceFill([
            'status' => DailyBriefingStatus::Failed,
            'error_message' => Str::limit($exception->getMessage(), 2_000, ''),
        ])->save();
    }

    private function existingBriefing(int $userId): ?DailyBriefing
    {
        return DailyBriefing::query()
            ->where('user_id', $userId)
            ->whereDate('briefing_date', $this->briefingDate)
            ->where('is_test', false)
            ->first();
    }
}
