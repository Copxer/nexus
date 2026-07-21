<?php

namespace App\Http\Controllers\Settings;

use App\Domain\DailyBriefings\Actions\GenerateDailyBriefingAction;
use App\Domain\DailyBriefings\Jobs\SendDailyBriefingJob;
use App\Domain\DailyBriefings\Queries\GetDailyBriefingInputQuery;
use App\Enums\DailyBriefingStatus;
use App\Http\Controllers\Controller;
use App\Models\AlertNotificationChannel;
use App\Models\DailyBriefing;
use App\Models\DailyBriefingPreference;
use App\Models\Project;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DailyBriefingPreferenceController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $preference = DailyBriefingPreference::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'enabled' => false,
                'delivery_time' => '08:00:00',
                'timezone' => config('app.timezone', 'UTC'),
                'channel_id' => null,
                'include_projects' => null,
            ],
        );

        return Inertia::render('Settings/DailyBriefing', [
            'preference' => $this->preferencePayload($preference),
            'channels' => $this->verifiedChannels($user->id),
            'projects' => $this->projects($user->id),
            'status' => $this->statusPayload($user->id),
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $this->validatePreference($request);

        $preference = DailyBriefingPreference::query()->firstOrNew(['user_id' => $user->id]);
        $preference->fill([
            'enabled' => $validated['enabled'],
            'delivery_time' => $this->normalizeTime($validated['delivery_time']),
            'timezone' => $validated['timezone'],
            'channel_id' => $validated['channel_id'] ?? null,
            'include_projects' => $validated['include_projects'] ?? null,
        ]);
        $preference->save();

        return back()->with('status', 'Daily briefing preferences saved.');
    }

    public function sendTest(
        Request $request,
        GetDailyBriefingInputQuery $inputQuery,
        GenerateDailyBriefingAction $generate,
    ): RedirectResponse {
        $user = $request->user();
        $preference = DailyBriefingPreference::query()
            ->where('user_id', $user->id)
            ->first();

        if ($preference === null || ! $preference->enabled) {
            return back()->with('error', 'Enable daily briefings before sending a test.');
        }

        if ($preference->channel_id !== null && $this->ownedVerifiedChannel($user->id, $preference->channel_id) === null) {
            return back()->with('error', 'Selected channel must be enabled, verified, and owned by you.');
        }

        $briefingDate = CarbonImmutable::now($preference->timezone)->subDay()->toDateString();
        $snapshot = $inputQuery->execute($user, $briefingDate, $preference->timezone, $preference->include_projects);
        $briefing = $generate->execute($user, $snapshot, isTest: true);

        if ($briefing->status !== DailyBriefingStatus::Generated) {
            return back()->with('error', 'Test briefing generation failed: '.$briefing->error_message);
        }

        try {
            (new SendDailyBriefingJob($briefing->id, updateLastSentForDate: false))->handle();
        } catch (Throwable $exception) {
            return back()->with('error', 'Test briefing delivery failed: '.$exception->getMessage());
        }

        $briefing->refresh();

        if ($briefing->status !== DailyBriefingStatus::Delivered) {
            return back()->with('error', 'Test briefing delivery failed: '.$briefing->error_message);
        }

        return back()->with('status', 'Test daily briefing sent.');
    }

    /** @return array<string, mixed> */
    private function validatePreference(Request $request): array
    {
        $userId = $request->user()->id;

        $validator = Validator::make($request->all(), [
            'enabled' => ['required', 'boolean'],
            'delivery_time' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'channel_id' => ['nullable', 'integer'],
            'include_projects' => ['nullable', 'array'],
            'include_projects.*' => ['integer'],
        ]);

        $validator->after(function ($validator) use ($request, $userId): void {
            $channelId = $request->integer('channel_id') ?: null;
            if ($channelId !== null && $this->ownedVerifiedChannel($userId, $channelId) === null) {
                $validator->errors()->add('channel_id', 'Select an enabled, verified channel you own.');
            }

            $projectIds = collect($request->input('include_projects', []))
                ->filter(fn ($id): bool => $id !== null && $id !== '')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();

            if ($projectIds->isEmpty()) {
                return;
            }

            $ownedCount = Project::query()
                ->where('owner_user_id', $userId)
                ->whereIn('id', $projectIds)
                ->count();

            if ($ownedCount !== $projectIds->count()) {
                $validator->errors()->add('include_projects', 'Project filter can only include your projects.');
            }
        });

        $validated = $validator->validate();
        $validated['include_projects'] = collect($validated['include_projects'] ?? [])
            ->filter(fn ($id): bool => $id !== null && $id !== '')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($validated['include_projects'] === []) {
            $validated['include_projects'] = null;
        }

        return $validated;
    }

    private function ownedVerifiedChannel(int $userId, int $channelId): ?AlertNotificationChannel
    {
        return AlertNotificationChannel::query()
            ->whereKey($channelId)
            ->where('user_id', $userId)
            ->where('enabled', true)
            ->whereNotNull('verified_at')
            ->first();
    }

    private function normalizeTime(string $time): string
    {
        return strlen($time) === 5 ? "{$time}:00" : $time;
    }

    /** @return array<string, mixed> */
    private function preferencePayload(DailyBriefingPreference $preference): array
    {
        return [
            'enabled' => $preference->enabled,
            'delivery_time' => substr((string) $preference->delivery_time, 0, 5),
            'timezone' => $preference->timezone,
            'channel_id' => $preference->channel_id,
            'include_projects' => $preference->include_projects ?? [],
            'last_sent_for_date' => $preference->last_sent_for_date?->toDateString(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function verifiedChannels(int $userId): array
    {
        return AlertNotificationChannel::query()
            ->where('user_id', $userId)
            ->where('enabled', true)
            ->whereNotNull('verified_at')
            ->orderBy('name')
            ->get(['id', 'kind', 'name'])
            ->map(fn (AlertNotificationChannel $channel): array => [
                'id' => $channel->id,
                'kind' => $channel->kind->value,
                'kind_label' => $channel->kind->label(),
                'name' => $channel->name,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function projects(int $userId): array
    {
        return Project::query()
            ->where('owner_user_id', $userId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
            ])
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function statusPayload(int $userId): ?array
    {
        $briefing = DailyBriefing::query()
            ->where('user_id', $userId)
            ->latest('briefing_date')
            ->latest('id')
            ->first();

        if ($briefing === null) {
            return null;
        }

        return [
            'briefing_date' => $briefing->briefing_date->toDateString(),
            'status' => $briefing->status->value,
            'generated_at' => $briefing->generated_at?->diffForHumans(),
            'delivered_at' => $briefing->delivered_at?->diffForHumans(),
            'error_message' => $briefing->error_message,
        ];
    }
}
