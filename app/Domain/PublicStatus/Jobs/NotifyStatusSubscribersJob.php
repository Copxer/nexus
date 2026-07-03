<?php

namespace App\Domain\PublicStatus\Jobs;

use App\Mail\PublicStatusIncidentMail;
use App\Models\Alert;
use App\Models\PublicStatusSubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Spec 047 — fan an alert transition (trigger / resolve) out to every
 * confirmed public subscriber of the alert's project.
 *
 * `ShouldBeUnique` keyed on `(alert_id, event)` collapses duplicate
 * dispatches — the listener idempotency layer already dedupes on the
 * event side, but a queue-side belt-and-braces guard means a
 * bug-in-listener retry can't double-mail subscribers.
 *
 * Skipped cases (all silent no-ops so the queue doesn't churn):
 *   - Alert row missing (deleted between dispatch and handle).
 *   - Alert has no project_id.
 *   - Project has `public_status_enabled = false`.
 *   - No confirmed subscribers on the project.
 */
class NotifyStatusSubscribersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $alertId,
        public readonly string $event, // 'triggered' | 'resolved'
    ) {}

    public function uniqueId(): string
    {
        return "{$this->alertId}:{$this->event}";
    }

    public function handle(): void
    {
        $alert = Alert::query()->with('project')->find($this->alertId);

        if ($alert === null || $alert->project_id === null) {
            return;
        }

        $project = $alert->project;

        if ($project === null || ! $project->public_status_enabled) {
            return;
        }

        PublicStatusSubscriber::query()
            ->where('project_id', $project->id)
            ->whereNotNull('confirmed_at')
            ->chunkById(100, function ($subscribers) use ($project, $alert): void {
                foreach ($subscribers as $subscriber) {
                    Mail::to($subscriber->email)->send(
                        new PublicStatusIncidentMail(
                            project: $project,
                            subscriber: $subscriber,
                            alert: $alert,
                            event: $this->event,
                        ),
                    );
                }
            });
    }
}
