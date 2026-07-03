<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Contracts\NotificationChannelDriver;
use App\Domain\Notifications\DataTransferObjects\AlertNotificationPayload;
use App\Domain\Notifications\Drivers\EmailChannelDriver;
use App\Domain\Notifications\Drivers\GenericWebhookChannelDriver;
use App\Domain\Notifications\Drivers\SlackChannelDriver;
use App\Domain\Notifications\Jobs\DispatchAlertNotificationJob;
use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertSeverity;
use App\Enums\NotificationChannelKind;
use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\AlertNotificationChannel;
use App\Models\AlertNotificationPreference;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Coordinator for spec 042's outbound notifications.
 *
 * `dispatchFor` (triggered alerts) and `dispatchResolutionFor`
 * (resolved alerts) share the same routing: read matching
 * preferences → dispatch one job per channel.
 *
 * Dedupe runs here at the service layer: if an alert with the same
 * `(source, source_id, type)` already lit up a delivery for the same
 * channel within the DEDUPE_WINDOW, the second call is silently
 * dropped so a flap storm can't burn the operator's Slack.
 *
 * Rate limit lives in the job (see `DispatchAlertNotificationJob`)
 * because per-user × per-channel budgets are cheaper to enforce with
 * RateLimiter::attempt after the job has already resolved the
 * preference row.
 */
class AlertNotificationService
{
    /** Same-fingerprint suppression window for delivery dedupe. */
    public const DEDUPE_WINDOW_MINUTES = 5;

    public function dispatchFor(Alert $alert): void
    {
        $this->fanOut($alert, event: 'alert.triggered', resolution: false);
    }

    public function dispatchResolutionFor(Alert $alert): void
    {
        $this->fanOut($alert, event: 'alert.resolved', resolution: true);
    }

    private function fanOut(Alert $alert, string $event, bool $resolution): void
    {
        $preferences = $this->matchingPreferences($alert, $resolution);

        if ($preferences->isEmpty()) {
            return;
        }

        $payload = AlertNotificationPayload::fromAlert($alert, $event);

        foreach ($preferences as $preference) {
            $channel = $preference->channel;

            if ($channel === null) {
                continue;
            }

            $skipReason = $this->skipReasonFor($alert, $channel);

            if ($skipReason !== null) {
                AlertDelivery::query()->create([
                    'alert_id' => $alert->id,
                    'channel_id' => $channel->id,
                    'status' => AlertDeliveryStatus::Skipped->value,
                    'error_message' => $skipReason,
                    'payload' => $payload->toArray(),
                ]);

                continue;
            }

            DispatchAlertNotificationJob::dispatch(
                alertId: $alert->id,
                channelId: $channel->id,
                event: $event,
            );
        }
    }

    /**
     * @return Collection<int, AlertNotificationPreference>
     */
    private function matchingPreferences(Alert $alert, bool $resolution): Collection
    {
        $severityRank = $this->severityRank($alert->severity);
        $sourceValue = $alert->source->value;

        $query = AlertNotificationPreference::query()
            ->with(['channel'])
            ->where('enabled', true);

        if ($resolution) {
            $query->where('notify_on_resolve', true);
        }

        $preferences = $query->get();

        return $preferences->filter(function (AlertNotificationPreference $preference) use ($severityRank, $sourceValue) {
            $prefRank = $this->severityRank($preference->min_severity);
            if ($severityRank < $prefRank) {
                return false;
            }

            $sources = $preference->sources ?? [];
            if (! empty($sources) && ! in_array($sourceValue, $sources, true)) {
                return false;
            }

            return true;
        })->values();
    }

    private function skipReasonFor(Alert $alert, AlertNotificationChannel $channel): ?string
    {
        if (! $channel->enabled) {
            return 'channel_disabled';
        }

        if ($channel->verified_at === null) {
            return 'channel_unverified';
        }

        if ($this->isDeduped($alert, $channel)) {
            return 'deduped';
        }

        return null;
    }

    private function isDeduped(Alert $alert, AlertNotificationChannel $channel): bool
    {
        return AlertDelivery::query()
            ->where('channel_id', $channel->id)
            ->whereHas('alert', function ($q) use ($alert): void {
                $q->where('source', $alert->source->value)
                    ->where('source_id', $alert->source_id)
                    ->where('type', $alert->type);
            })
            ->where('created_at', '>=', Carbon::now()->subMinutes(self::DEDUPE_WINDOW_MINUTES))
            ->where('id', '<', $alert->id ? PHP_INT_MAX : 0)
            ->exists();
    }

    private function severityRank(AlertSeverity|string $severity): int
    {
        $value = $severity instanceof AlertSeverity ? $severity->value : $severity;

        return match ($value) {
            'info' => 1,
            'warning' => 2,
            'critical' => 3,
            default => 0,
        };
    }

    /**
     * Container binding helper — resolves the strategy driver by
     * channel kind. Used by DispatchAlertNotificationJob + tests.
     */
    public static function driverFor(NotificationChannelKind $kind): NotificationChannelDriver
    {
        return match ($kind) {
            NotificationChannelKind::Email => app(EmailChannelDriver::class),
            NotificationChannelKind::Slack => app(SlackChannelDriver::class),
            NotificationChannelKind::Webhook => app(GenericWebhookChannelDriver::class),
        };
    }
}
