<?php

namespace App\Http\Controllers\PublicStatus;

use App\Http\Controllers\Controller;
use App\Models\PublicStatusSubscriber;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Spec 047 — RFC 8058 one-click unsubscribe. Token-only lookup so the
 * link works from any inbox without login. Deletes the row on hit;
 * unknown token 404.
 */
class UnsubscribeController extends Controller
{
    public function __invoke(string $token): Response
    {
        $subscriber = PublicStatusSubscriber::query()
            ->where('unsubscribe_token', $token)
            ->with('project:id,name,slug')
            ->first();

        abort_unless($subscriber !== null, 404);

        $projectName = $subscriber->project?->name;
        $projectSlug = $subscriber->project?->slug;
        $subscriber->delete();

        return Inertia::render('Status/Unsubscribed', [
            'project' => [
                'name' => $projectName,
                'slug' => $projectSlug,
            ],
        ]);
    }
}
