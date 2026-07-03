/**
 * Spec 043 — client-side helper for the debounced palette server search.
 *
 * `search(q, signal)` fires a single `fetch` to `/palette/search?q=...`
 * and returns the JSON payload. Callers wire debouncing + AbortController
 * at the palette-component layer so an in-flight request can be cancelled
 * when a new keystroke arrives.
 */

export interface PaletteAsyncEntity {
    kind: 'issue' | 'pull_request' | 'alert';
    id: number;
    label: string;
    subtitle: string | null;
    url: string;
    badge: string | null;
}

export interface PaletteAsyncResults {
    workItems: PaletteAsyncEntity[];
    alerts: PaletteAsyncEntity[];
}

export async function searchPaletteEntities(
    query: string,
    signal?: AbortSignal,
): Promise<PaletteAsyncResults> {
    const url = `${route('palette.search')}?q=${encodeURIComponent(query)}`;

    const response = await fetch(url, {
        method: 'GET',
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal,
    });

    if (!response.ok) {
        // 429 (throttled) / 401 (guest) / 500 — all resolve to an empty
        // shape so the palette gracefully renders "no results" instead
        // of blowing up.
        return { workItems: [], alerts: [] };
    }

    const data = (await response.json()) as PaletteAsyncResults;
    return {
        workItems: Array.isArray(data.workItems) ? data.workItems : [],
        alerts: Array.isArray(data.alerts) ? data.alerts : [],
    };
}
