<?php

namespace App\Http\Controllers;

use App\Domain\Palette\Queries\SearchPaletteEntitiesQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Spec 043 — async server-side palette search endpoint.
 *
 * Fires from the client palette on debounced keystrokes (200ms). Scopes
 * results to the authenticated user's projects via
 * `SearchPaletteEntitiesQuery`. Throttled at 30/min per §5 of the
 * operator checklist.
 */
class PaletteSearchController extends Controller
{
    public function __invoke(Request $request, SearchPaletteEntitiesQuery $query): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'sometimes|nullable|string|max:120',
        ]);

        $q = trim((string) ($validated['q'] ?? ''));

        if ($q === '' || mb_strlen($q) < 2) {
            // Two-character floor: a single letter would flood the
            // results with weak matches for no UX gain. Returning
            // an empty payload keeps the client's `no results yet`
            // path uniform.
            return response()->json(['workItems' => [], 'alerts' => []]);
        }

        return response()->json($query->execute($request->user(), $q));
    }
}
