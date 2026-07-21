<?php

namespace App\Domain\DailyBriefings\Jobs;

use App\Enums\DailyBriefingStatus;
use App\Models\DailyBriefing;
use App\Models\DailyBriefingPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchDueDailyBriefingsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        if (! config('services.llm.enabled', false)) {
            return;
        }

        DailyBriefingPreference::query()
            ->where('enabled', true)
            ->with('user:id')
            ->chunkById(100, function ($preferences): void {
                foreach ($preferences as $preference) {
                    $this->dispatchIfDue($preference);
                }
            });
    }

    private function dispatchIfDue(DailyBriefingPreference $preference): void
    {
        $timezone = $preference->timezone ?: config('app.timezone', 'UTC');
        $now = now($timezone)->toImmutable();
        $deliveryAt = $now->setTimeFromTimeString((string) $preference->delivery_time);

        if ($now->lessThan($deliveryAt)) {
            return;
        }

        $briefingDate = $now->subDay()->toDateString();

        if ($preference->last_sent_for_date?->toDateString() === $briefingDate) {
            return;
        }

        if ($this->briefingAlreadyDelivered((int) $preference->user_id, $briefingDate)) {
            return;
        }

        GenerateDailyBriefingJob::dispatch((int) $preference->user_id, $briefingDate);
    }

    private function briefingAlreadyDelivered(int $userId, string $briefingDate): bool
    {
        return DailyBriefing::query()
            ->where('user_id', $userId)
            ->whereDate('briefing_date', $briefingDate)
            ->where('is_test', false)
            ->where('status', DailyBriefingStatus::Delivered->value)
            ->exists();
    }
}
