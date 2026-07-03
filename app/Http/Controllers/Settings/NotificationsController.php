<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Notifications\DataTransferObjects\AlertNotificationPayload;
use App\Domain\Notifications\Jobs\DispatchAlertNotificationJob;
use App\Domain\Notifications\Services\AlertNotificationService;
use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertSeverity;
use App\Enums\AlertSource;
use App\Enums\NotificationChannelKind;
use App\Http\Controllers\Controller;
use App\Models\AlertDelivery;
use App\Models\AlertNotificationChannel;
use App\Models\AlertNotificationPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Spec 042 — Settings → Notifications. Single controller carrying
 * three logical tabs (Channels / Rules / Deliveries) because the
 * page shares one JSON payload + one Inertia route.
 *
 * Every mutation is per-user scoped — a user can only touch their
 * own channels / preferences / deliveries.
 */
class NotificationsController extends Controller
{
    private const DELIVERIES_PER_PAGE = 30;

    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $channels = AlertNotificationChannel::query()
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get()
            ->map(fn (AlertNotificationChannel $c): array => [
                'id' => $c->id,
                'kind' => $c->kind->value,
                'kind_label' => $c->kind->label(),
                'name' => $c->name,
                // Never leak signing_secret. `config` is `encrypted:array`
                // + `$hidden` — read here explicitly.
                'config_preview' => $this->configPreview($c),
                'enabled' => $c->enabled,
                'verified_at' => $c->verified_at?->diffForHumans(),
                'verified' => $c->verified_at !== null,
            ]);

        $preferences = AlertNotificationPreference::query()
            ->where('user_id', $userId)
            ->with('channel:id,name,kind')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AlertNotificationPreference $p): array => [
                'id' => $p->id,
                'channel_id' => $p->channel_id,
                'channel_name' => $p->channel?->name,
                'channel_kind' => $p->channel?->kind?->value,
                'min_severity' => $p->min_severity->value,
                'sources' => $p->sources ?? [],
                'enabled' => $p->enabled,
                'notify_on_resolve' => $p->notify_on_resolve,
                'rate_limit_per_hour' => $p->rate_limit_per_hour,
            ]);

        $deliveries = AlertDelivery::query()
            ->whereHas('channel', fn ($q) => $q->where('user_id', $userId))
            ->with(['alert:id,title,severity,source,type', 'channel:id,name,kind'])
            ->latest('id')
            ->paginate(self::DELIVERIES_PER_PAGE)
            ->through(fn (AlertDelivery $d): array => [
                'id' => $d->id,
                'alert_id' => $d->alert_id,
                'alert_title' => $d->alert?->title,
                'alert_severity' => $d->alert?->severity?->value,
                'alert_source' => $d->alert?->source?->value,
                'channel_name' => $d->channel?->name,
                'channel_kind' => $d->channel?->kind?->value,
                'status' => $d->status->value,
                'status_tone' => $d->status->badgeTone(),
                'attempts' => $d->attempts,
                'error_message' => $d->error_message,
                'last_attempt_at' => $d->last_attempt_at?->diffForHumans(),
                'sent_at' => $d->sent_at?->diffForHumans(),
                'created_at' => $d->created_at?->diffForHumans(),
            ]);

        return Inertia::render('Settings/Notifications/Index', [
            'channels' => $channels,
            'preferences' => $preferences,
            'deliveries' => $deliveries,
            'options' => [
                'kinds' => array_map(
                    fn (NotificationChannelKind $k): array => [
                        'value' => $k->value,
                        'label' => $k->label(),
                    ],
                    NotificationChannelKind::cases(),
                ),
                'severities' => array_map(
                    fn (AlertSeverity $s): string => $s->value,
                    AlertSeverity::cases(),
                ),
                'sources' => array_map(
                    fn (AlertSource $s): string => $s->value,
                    AlertSource::cases(),
                ),
            ],
        ]);
    }

    public function storeChannel(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => 'required|in:'.implode(',', array_column(NotificationChannelKind::cases(), 'value')),
            'name' => 'required|string|max:120',
            'config' => 'required|array',
        ]);

        $validated['config'] = $this->sanitizeConfig(
            NotificationChannelKind::from($validated['kind']),
            $validated['config'],
        );

        AlertNotificationChannel::query()->create([
            'user_id' => $request->user()->id,
            'kind' => $validated['kind'],
            'name' => $validated['name'],
            'config' => $validated['config'],
            'enabled' => true,
            'verified_at' => null,
        ]);

        return back()->with('status', 'Channel added. Send a test to verify it.');
    }

    public function updateChannel(Request $request, AlertNotificationChannel $channel): RedirectResponse
    {
        $this->authorizeOwner($request, $channel);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'config' => 'sometimes|required|array',
            'enabled' => 'sometimes|boolean',
        ]);

        if (array_key_exists('config', $validated)) {
            $validated['config'] = $this->sanitizeConfig($channel->kind, $validated['config']);
            // A config change invalidates prior verification.
            $validated['verified_at'] = null;
        }

        $channel->fill($validated)->save();

        return back()->with('status', 'Channel updated.');
    }

    public function destroyChannel(Request $request, AlertNotificationChannel $channel): RedirectResponse
    {
        $this->authorizeOwner($request, $channel);
        $channel->delete();

        return back()->with('status', 'Channel deleted.');
    }

    public function testChannel(Request $request, AlertNotificationChannel $channel): RedirectResponse
    {
        $this->authorizeOwner($request, $channel);

        $payload = AlertNotificationPayload::testPayload();
        $driver = AlertNotificationService::driverFor($channel->kind);

        try {
            $driver->send($channel, $payload);
            $channel->forceFill(['verified_at' => Carbon::now()])->save();

            return back()->with('status', 'Test notification sent. Channel is verified.');
        } catch (Throwable $e) {
            return back()->with('error', 'Test failed: '.$e->getMessage());
        }
    }

    public function storePreference(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'channel_id' => 'required|integer|exists:alert_notification_channels,id',
            'min_severity' => 'required|in:'.implode(',', array_column(AlertSeverity::cases(), 'value')),
            'sources' => 'sometimes|nullable|array',
            'sources.*' => 'in:'.implode(',', array_column(AlertSource::cases(), 'value')),
            'notify_on_resolve' => 'sometimes|boolean',
            'rate_limit_per_hour' => 'sometimes|nullable|integer|min:1|max:1000',
        ]);

        $channel = AlertNotificationChannel::query()->findOrFail($validated['channel_id']);
        $this->authorizeOwner($request, $channel);

        AlertNotificationPreference::query()->create([
            'user_id' => $request->user()->id,
            'channel_id' => $validated['channel_id'],
            'min_severity' => $validated['min_severity'],
            'sources' => $validated['sources'] ?? null,
            'enabled' => true,
            'notify_on_resolve' => $validated['notify_on_resolve'] ?? false,
            'rate_limit_per_hour' => $validated['rate_limit_per_hour'] ?? null,
        ]);

        return back()->with('status', 'Rule added.');
    }

    public function updatePreference(Request $request, AlertNotificationPreference $preference): RedirectResponse
    {
        if ($preference->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'min_severity' => 'sometimes|required|in:'.implode(',', array_column(AlertSeverity::cases(), 'value')),
            'sources' => 'sometimes|nullable|array',
            'sources.*' => 'in:'.implode(',', array_column(AlertSource::cases(), 'value')),
            'enabled' => 'sometimes|boolean',
            'notify_on_resolve' => 'sometimes|boolean',
            'rate_limit_per_hour' => 'sometimes|nullable|integer|min:1|max:1000',
        ]);

        $preference->fill($validated)->save();

        return back()->with('status', 'Rule updated.');
    }

    public function destroyPreference(Request $request, AlertNotificationPreference $preference): RedirectResponse
    {
        if ($preference->user_id !== $request->user()->id) {
            abort(403);
        }

        $preference->delete();

        return back()->with('status', 'Rule deleted.');
    }

    public function retryDelivery(Request $request, AlertDelivery $delivery): RedirectResponse
    {
        $userId = $request->user()->id;
        $delivery->loadMissing('channel:id,user_id');

        if ($delivery->channel?->user_id !== $userId) {
            abort(403);
        }

        if ($delivery->status !== AlertDeliveryStatus::Failed) {
            return back()->with('error', 'Only failed deliveries can be retried.');
        }

        $delivery->forceFill([
            'status' => AlertDeliveryStatus::Pending->value,
            'error_message' => null,
        ])->save();

        DispatchAlertNotificationJob::dispatch(
            alertId: $delivery->alert_id,
            channelId: $delivery->channel_id,
        );

        return back()->with('status', 'Delivery re-queued.');
    }

    private function authorizeOwner(Request $request, AlertNotificationChannel $channel): void
    {
        if ($channel->user_id !== $request->user()->id) {
            abort(403);
        }
    }

    /**
     * Strip unexpected keys per kind so the encrypted `config` column
     * stays predictable. Anything not on the whitelist is dropped.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private function sanitizeConfig(NotificationChannelKind $kind, array $config): array
    {
        $allowed = match ($kind) {
            NotificationChannelKind::Email => ['to'],
            NotificationChannelKind::Slack => ['webhook_url'],
            NotificationChannelKind::Webhook => ['url', 'signing_secret'],
        };

        $out = [];
        foreach ($allowed as $key) {
            if (isset($config[$key]) && is_string($config[$key]) && $config[$key] !== '') {
                $out[$key] = $config[$key];
            }
        }

        return $out;
    }

    /**
     * Non-secret preview shape for the Channels table. Hides the Slack
     * URL / webhook URL / signing_secret; surfaces only the "to"
     * email + a marker for HMAC-signed webhooks so the UI can flag it.
     *
     * @return array<string, mixed>
     */
    private function configPreview(AlertNotificationChannel $channel): array
    {
        return match ($channel->kind) {
            NotificationChannelKind::Email => [
                'to' => $channel->config['to'] ?? null,
            ],
            NotificationChannelKind::Slack => [
                'webhook_host' => $this->hostFromUrl($channel->config['webhook_url'] ?? ''),
            ],
            NotificationChannelKind::Webhook => [
                'url_host' => $this->hostFromUrl($channel->config['url'] ?? ''),
                'signed' => ! empty($channel->config['signing_secret']),
            ],
        };
    }

    private function hostFromUrl(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $parsed = parse_url($url);

        return $parsed['host'] ?? null;
    }
}
