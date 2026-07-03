<?php

namespace App\Http\Controllers\PublicStatus;

use App\Http\Controllers\Controller;
use App\Mail\PublicStatusSubscribeConfirmationMail;
use App\Models\Project;
use App\Models\PublicStatusSubscriber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Spec 047 — public subscribe endpoint. Double opt-in: the row lands
 * with `confirmed_at = null`; the operator receives a mail with a
 * confirm link (`GET /status/{slug}/confirm/{token}`).
 *
 * Idempotent: repeat POST with same `(project, email)` refreshes the
 * confirmation token + re-sends the confirm mail without creating a
 * second row (relies on the composite unique index).
 *
 * `honeypot` is a hidden field: any non-empty submission from a form
 * that ships an invisible input is a bot. Returns 422 with a generic
 * validation error so the bot can't distinguish success from failure.
 */
class SubscribeController extends Controller
{
    public function __invoke(Request $request, Project $project): RedirectResponse
    {
        abort_unless($project->public_status_enabled, 404);

        $validated = $request->validate([
            'email' => 'required|email:filter|max:190',
            'honeypot' => 'sometimes|nullable|string',
        ]);

        if (! empty($validated['honeypot'])) {
            return back()->with('status', 'Check your inbox to confirm.');
        }

        $subscriber = PublicStatusSubscriber::query()->updateOrCreate(
            [
                'project_id' => $project->id,
                'email' => $validated['email'],
            ],
            [
                'confirmation_token' => PublicStatusSubscriber::freshToken(),
                'unsubscribe_token' => PublicStatusSubscriber::freshToken(),
                'confirmed_at' => null,
            ],
        );

        Mail::to($subscriber->email)->send(
            new PublicStatusSubscribeConfirmationMail($project, $subscriber),
        );

        return back()->with('status', 'Check your inbox to confirm.');
    }
}
