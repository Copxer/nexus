<?php

namespace App\Http\Controllers\Settings;

use App\Domain\GitHub\Jobs\ProcessGitHubWebhookJob;
use App\Enums\WebhookDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Models\WebhookDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Spec 037 — webhook delivery list + retry. Single-tenant phase-1:
 * every verified user sees every delivery row. When multi-tenant
 * scoping lands, restrict via repository ownership.
 *
 * The index view is URL-backed (status / event / repo search) so
 * filters survive a refresh and are linkable. Retry is a single-row
 * action that re-dispatches `ProcessGitHubWebhookJob` against the
 * stored payload + signature (the row is preserved for forensic
 * audit; the action just kicks the job).
 */
class WebhookDeliveryController extends Controller
{
    private const PER_PAGE = 30;

    public function __invoke(Request $request): Response
    {
        $statusValues = array_map(
            fn (WebhookDeliveryStatus $case): string => $case->value,
            WebhookDeliveryStatus::cases(),
        );

        $validated = $request->validate([
            'status' => 'sometimes|nullable|in:'.implode(',', [...$statusValues, 'all']),
            'event' => 'sometimes|nullable|string|max:64',
            'repository' => 'sometimes|nullable|string|max:128',
        ]);

        $rawStatus = $validated['status'] ?? null;
        $event = $validated['event'] ?? null;
        $repository = $validated['repository'] ?? null;

        $query = WebhookDelivery::query()->latest('received_at');

        // Default view = "everything" (no implicit Open default, unlike
        // Alerts) because the page is an admin/debug surface and
        // showing one status by default would hide background failures.
        if ($rawStatus !== null && $rawStatus !== 'all') {
            $query->where('status', $rawStatus);
        }

        if ($event !== null && $event !== '') {
            $query->where('event', $event);
        }

        if ($repository !== null && $repository !== '') {
            $query->where('repository_full_name', 'like', "%{$repository}%");
        }

        $deliveries = $query
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (WebhookDelivery $row): array => [
                'id' => $row->id,
                'github_delivery_id' => $row->github_delivery_id,
                'event' => $row->event,
                'action' => $row->action,
                'repository_full_name' => $row->repository_full_name,
                'status' => $row->status?->value,
                'status_tone' => $row->status?->badgeTone(),
                'error_message' => $row->error_message,
                'received_at' => $row->received_at?->diffForHumans(),
                'received_at_iso' => $row->received_at?->toIso8601String(),
                'processed_at' => $row->processed_at?->diffForHumans(),
            ]);

        return Inertia::render('Settings/WebhookDeliveries', [
            'deliveries' => $deliveries,
            'filters' => [
                'status' => $rawStatus ?? 'all',
                'event' => $event ?? '',
                'repository' => $repository ?? '',
            ],
            'filterOptions' => [
                'statuses' => $statusValues,
                'events' => WebhookDelivery::query()
                    ->distinct()
                    ->orderBy('event')
                    ->pluck('event')
                    ->all(),
            ],
        ]);
    }

    /**
     * Spec 037 — re-dispatch the webhook job against the persisted
     * payload. Resets the row to `Received` so a successful retry
     * lands as `Processed`. The original `received_at` is preserved
     * (the row's identity hasn't changed).
     */
    public function retry(Request $request, WebhookDelivery $delivery): RedirectResponse
    {
        if ($delivery->status !== WebhookDeliveryStatus::Failed) {
            return back()->with('error', 'Only failed deliveries can be retried.');
        }

        $delivery->forceFill([
            'status' => WebhookDeliveryStatus::Received->value,
            'error_message' => null,
            'processed_at' => null,
        ])->save();

        ProcessGitHubWebhookJob::dispatch($delivery->id);

        return back()->with('status', 'Webhook re-queued.');
    }
}
