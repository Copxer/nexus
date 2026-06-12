<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Spec 036 — per-user theme preference. Single-action invokable
 * controller; validates the submitted value against the three
 * allowed presets and persists. The actual `<html class="…">`
 * toggling lives client-side in `AppLayout.vue` (it reads the
 * shared `auth.user.theme` prop on mount).
 *
 * `system` defers to `prefers-color-scheme` at render time; the
 * server doesn't need to know the resolved value.
 */
class ThemeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'theme' => 'required|in:dark,light,system',
        ]);

        $request->user()->forceFill(['theme' => $validated['theme']])->save();

        return back()->with('status', 'Theme updated.');
    }
}
