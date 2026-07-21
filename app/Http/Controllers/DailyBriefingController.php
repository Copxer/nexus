<?php

namespace App\Http\Controllers;

use App\Enums\DailyBriefingStatus;
use App\Enums\NotificationChannelKind;
use App\Models\AlertNotificationChannel;
use App\Models\DailyBriefing;
use App\Models\DailyBriefingPreference;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DailyBriefingController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $channel = $this->configuredChannel($user->id);

        $briefings = DailyBriefing::query()
            ->where('user_id', $user->id)
            ->where('is_test', false)
            ->whereNotNull('summary')
            ->latest('briefing_date')
            ->latest('id')
            ->get()
            ->map(fn (DailyBriefing $briefing): array => $this->rowPayload($briefing, $channel))
            ->all();

        return Inertia::render('DailyBriefings/Index', [
            'briefings' => $briefings,
        ]);
    }

    /** @return array<string, mixed> */
    private function rowPayload(DailyBriefing $briefing, ?AlertNotificationChannel $channel): array
    {
        return [
            'id' => $briefing->id,
            'briefing_date' => $briefing->briefing_date->toDateString(),
            'status' => $briefing->status->value,
            'status_tone' => $this->statusTone($briefing->status),
            'channel' => $this->channelPayload($channel),
            'summary_preview' => str($briefing->summary)->squish()->limit(180)->toString(),
            'summary' => $briefing->summary,
            'highlights' => $briefing->highlights ?? [],
            'risks' => $briefing->risks ?? [],
            'generated_at' => $briefing->generated_at?->diffForHumans(),
            'delivered_at' => $briefing->delivered_at?->diffForHumans(),
            'prompt_version' => $briefing->prompt_version,
            'error_message' => $briefing->error_message,
        ];
    }

    private function configuredChannel(int $userId): ?AlertNotificationChannel
    {
        $preference = DailyBriefingPreference::query()
            ->where('user_id', $userId)
            ->first();

        if ($preference?->channel_id !== null) {
            return AlertNotificationChannel::query()
                ->whereKey($preference->channel_id)
                ->where('user_id', $userId)
                ->where('enabled', true)
                ->whereNotNull('verified_at')
                ->first();
        }

        return AlertNotificationChannel::query()
            ->where('user_id', $userId)
            ->where('kind', NotificationChannelKind::Email->value)
            ->where('enabled', true)
            ->whereNotNull('verified_at')
            ->orderBy('id')
            ->first();
    }

    /** @return array<string, string>|null */
    private function channelPayload(?AlertNotificationChannel $channel): ?array
    {
        if ($channel === null) {
            return null;
        }

        return [
            'kind' => $channel->kind->value,
            'kind_label' => $channel->kind->label(),
            'name' => $channel->name,
        ];
    }

    private function statusTone(DailyBriefingStatus $status): string
    {
        return match ($status) {
            DailyBriefingStatus::Delivered => 'success',
            DailyBriefingStatus::Failed => 'danger',
            DailyBriefingStatus::Skipped => 'muted',
            default => 'info',
        };
    }
}
