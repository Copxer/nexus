<?php

namespace App\Domain\DailyBriefings\Jobs;

use App\Domain\DailyBriefings\DataTransferObjects\DailyBriefingPayload;
use App\Domain\Notifications\Services\AlertNotificationService;
use App\Enums\DailyBriefingStatus;
use App\Enums\NotificationChannelKind;
use App\Models\AlertNotificationChannel;
use App\Models\DailyBriefing;
use App\Models\DailyBriefingPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SendDailyBriefingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $briefingId,
        public readonly bool $updateLastSentForDate = true,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->briefingId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(): void
    {
        $briefing = DailyBriefing::query()->find($this->briefingId);

        if ($briefing === null || $briefing->status === DailyBriefingStatus::Delivered) {
            return;
        }

        if ($briefing->summary === null || ! in_array($briefing->status, [
            DailyBriefingStatus::Generated,
            DailyBriefingStatus::Failed,
        ], true)) {
            return;
        }

        $preference = DailyBriefingPreference::query()
            ->where('user_id', $briefing->user_id)
            ->where('enabled', true)
            ->first();

        if ($preference === null) {
            $this->markFailed($briefing, 'Daily briefing preference is disabled or missing.');

            return;
        }

        $channel = $this->channelFor($preference);

        if ($channel === null) {
            $this->markFailed($briefing, 'No verified daily briefing delivery channel is available.');

            return;
        }

        try {
            AlertNotificationService::driverFor($channel->kind)->send(
                $channel,
                DailyBriefingPayload::fromBriefing($briefing),
            );

            DB::transaction(function () use ($briefing, $preference): void {
                $briefing->forceFill([
                    'status' => DailyBriefingStatus::Delivered,
                    'delivered_at' => now(),
                    'error_message' => null,
                ])->save();

                if ($this->updateLastSentForDate && ! $briefing->is_test) {
                    $preference->forceFill([
                        'last_sent_for_date' => $briefing->briefing_date->toDateString(),
                    ])->save();
                }
            });
        } catch (Throwable $exception) {
            $this->markFailed($briefing, $exception->getMessage());

            throw $exception;
        }
    }

    private function channelFor(DailyBriefingPreference $preference): ?AlertNotificationChannel
    {
        if ($preference->channel_id !== null) {
            return AlertNotificationChannel::query()
                ->whereKey($preference->channel_id)
                ->where('user_id', $preference->user_id)
                ->where('enabled', true)
                ->whereNotNull('verified_at')
                ->first();
        }

        return AlertNotificationChannel::query()
            ->where('user_id', $preference->user_id)
            ->where('kind', NotificationChannelKind::Email->value)
            ->where('enabled', true)
            ->whereNotNull('verified_at')
            ->orderBy('id')
            ->first();
    }

    private function markFailed(DailyBriefing $briefing, string $message): void
    {
        $briefing->forceFill([
            'status' => DailyBriefingStatus::Failed,
            'error_message' => Str::limit($message, 2_000, ''),
        ])->save();
    }

    public function failed(Throwable $exception): void
    {
        $briefing = DailyBriefing::query()->find($this->briefingId);

        if ($briefing === null) {
            return;
        }

        $this->markFailed($briefing, $exception->getMessage());
    }
}
