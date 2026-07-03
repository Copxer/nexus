<?php

namespace App\Domain\Notifications\Jobs;

use App\Domain\Notifications\DataTransferObjects\AlertNotificationPayload;
use App\Domain\Notifications\Services\AlertNotificationService;
use App\Enums\AlertDeliveryStatus;
use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\AlertNotificationChannel;
use App\Models\AlertNotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Ship one alert notification through one channel.
 *
 * `ShouldBeUnique` keyed on `(alert_id, channel_id, event)` collapses
 * duplicate dispatches (e.g. a `TriggerAlertAction` re-run while the
 * alert is still open — the service currently guards this at the
 * fan-out layer, but the queue-side guard is cheap belt + braces).
 *
 * Retry policy (§18): up to 3 attempts with exponential backoff.
 * A driver exception raises the attempt count; after the ceiling the
 * `failed()` hook lands the delivery as `status: failed` with the
 * last error message.
 */
class DispatchAlertNotificationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** Lock the (alert, channel, event) combo for 60s to collapse bursts. */
    public int $uniqueFor = 60;

    /** Per-channel default budget (30 sends / hour / user × channel). */
    public const DEFAULT_RATE_LIMIT_PER_HOUR = 30;

    public function __construct(
        public readonly int $alertId,
        public readonly int $channelId,
        public readonly string $event = 'alert.triggered',
    ) {}

    public function uniqueId(): string
    {
        return "{$this->alertId}:{$this->channelId}:{$this->event}";
    }

    /**
     * §18 backoff — 5s, 30s, 2 min.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(): void
    {
        $alert = Alert::query()->find($this->alertId);
        $channel = AlertNotificationChannel::query()->find($this->channelId);

        if ($alert === null || $channel === null) {
            return;
        }

        if (! $channel->enabled || $channel->verified_at === null) {
            $this->recordSkipped($alert, $channel, ! $channel->enabled ? 'channel_disabled' : 'channel_unverified');

            return;
        }

        $preference = AlertNotificationPreference::query()
            ->where('channel_id', $channel->id)
            ->first();

        $limit = $preference?->rate_limit_per_hour ?? self::DEFAULT_RATE_LIMIT_PER_HOUR;
        $rateKey = "notif:{$channel->user_id}:{$channel->id}";

        if (! RateLimiter::attempt($rateKey, $limit, fn () => true, 3600)) {
            $this->recordSkipped($alert, $channel, 'rate_limited');

            return;
        }

        $payload = AlertNotificationPayload::fromAlert($alert, $this->event);
        $driver = AlertNotificationService::driverFor($channel->kind);

        // Upsert the delivery row for observability + retry accounting.
        $delivery = AlertDelivery::query()->firstOrNew([
            'alert_id' => $alert->id,
            'channel_id' => $channel->id,
        ]);

        try {
            $driver->send($channel, $payload);

            $delivery->forceFill([
                'status' => AlertDeliveryStatus::Sent->value,
                'attempts' => $delivery->attempts + 1,
                'last_attempt_at' => Carbon::now(),
                'sent_at' => Carbon::now(),
                'error_message' => null,
                'payload' => $payload->toArray(),
            ])->save();
        } catch (Throwable $e) {
            $delivery->forceFill([
                'status' => AlertDeliveryStatus::Pending->value,
                'attempts' => $delivery->attempts + 1,
                'last_attempt_at' => Carbon::now(),
                'error_message' => $e->getMessage(),
                'payload' => $payload->toArray(),
            ])->save();

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $delivery = AlertDelivery::query()
            ->where('alert_id', $this->alertId)
            ->where('channel_id', $this->channelId)
            ->first();

        if ($delivery === null) {
            return;
        }

        $delivery->forceFill([
            'status' => AlertDeliveryStatus::Failed->value,
            'error_message' => $e->getMessage(),
        ])->save();
    }

    private function recordSkipped(Alert $alert, AlertNotificationChannel $channel, string $reason): void
    {
        AlertDelivery::query()->create([
            'alert_id' => $alert->id,
            'channel_id' => $channel->id,
            'status' => AlertDeliveryStatus::Skipped->value,
            'error_message' => $reason,
            'payload' => AlertNotificationPayload::fromAlert($alert, $this->event)->toArray(),
        ]);
    }
}
