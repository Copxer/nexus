<?php

namespace App\Domain\DailyBriefings\Jobs;

use App\Domain\DailyBriefings\Actions\GenerateDailyBriefingAction;
use App\Domain\DailyBriefings\Queries\GetDailyBriefingInputQuery;
use App\Enums\DailyBriefingStatus;
use App\Models\DailyBriefing;
use App\Models\DailyBriefingPreference;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateDailyBriefingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

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

        if ($this->alreadyGeneratedOrQueued($user->id)) {
            return;
        }

        DailyBriefing::query()->firstOrCreate(
            ['user_id' => $user->id, 'briefing_date' => CarbonImmutable::parse($this->briefingDate)->toDateString()],
            ['status' => DailyBriefingStatus::Pending->value],
        );

        $snapshot = $inputQuery->execute(
            $user,
            $this->briefingDate,
            $preference->timezone,
            $preference->include_projects,
        );

        $generate->execute($user, $snapshot);
    }

    private function alreadyGeneratedOrQueued(int $userId): bool
    {
        return DailyBriefing::query()
            ->where('user_id', $userId)
            ->whereDate('briefing_date', $this->briefingDate)
            ->whereIn('status', [
                DailyBriefingStatus::Pending->value,
                DailyBriefingStatus::Generated->value,
                DailyBriefingStatus::Delivered->value,
            ])
            ->exists();
    }
}
