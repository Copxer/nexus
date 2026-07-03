<?php

namespace App\Http\Controllers\PublicStatus;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\PublicStatusSubscriber;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Spec 047 — public confirmation link. `GET /status/{slug}/confirm/{token}`.
 * Flips `confirmed_at` on first hit; idempotent on repeated hits.
 * Unknown token 404.
 */
class ConfirmSubscriptionController extends Controller
{
    public function __invoke(Project $project, string $token): Response
    {
        abort_unless($project->public_status_enabled, 404);

        $subscriber = PublicStatusSubscriber::query()
            ->where('project_id', $project->id)
            ->where('confirmation_token', $token)
            ->first();

        abort_unless($subscriber !== null, 404);

        if ($subscriber->confirmed_at === null) {
            $subscriber->forceFill(['confirmed_at' => now()])->save();
        }

        return Inertia::render('Status/Confirmed', [
            'project' => [
                'name' => $project->name,
                'slug' => $project->slug,
            ],
        ]);
    }
}
