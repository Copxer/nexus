/**
 * Tiny fuzzy matcher used by the command palette. No external deps.
 *
 * Scoring tiers (higher = better):
 *   1000 — exact match
 *    800 — acronym match (query == initials of target words)
 *    600 — target starts with query
 *    500 — acronym starts with query
 *    300 - position — substring match (earlier position scores higher)
 *    100 — in-order subsequence match
 *      0 — no match (filtered out)
 */

export type ScoredItem<T> = { item: T; score: number };

export function fuzzyMatch<T>(
    items: readonly T[],
    query: string,
    getSearchable: (item: T) => readonly string[],
): ScoredItem<T>[] {
    const q = query.trim().toLowerCase();
    if (!q) {
        return items.map((item) => ({ item, score: 0 }));
    }

    const results: ScoredItem<T>[] = [];
    for (const item of items) {
        const haystacks = getSearchable(item);
        let best = 0;
        for (const raw of haystacks) {
            const score = scoreOne(raw.toLowerCase(), q);
            if (score > best) best = score;
        }
        if (best > 0) {
            results.push({ item, score: best });
        }
    }
    return results;
}

function scoreOne(haystack: string, query: string): number {
    if (haystack === query) return 1000;

    const acronym = haystack
        .split(/[\s\-_/]+/)
        .map((w) => w[0] ?? '')
        .join('');
    if (acronym === query) return 800;
    if (haystack.startsWith(query)) return 600;
    if (acronym.startsWith(query)) return 500;

    const idx = haystack.indexOf(query);
    if (idx >= 0) return Math.max(300 - idx, 101);

    // In-order subsequence: every query char appears in haystack in order.
    let qi = 0;
    for (let i = 0; i < haystack.length && qi < query.length; i++) {
        if (haystack[i] === query[qi]) qi++;
    }
    return qi === query.length ? 100 : 0;
}
