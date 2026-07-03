/**
 * Spec 043 — LRU-tracked recent commands for the command palette.
 *
 * Persists client-side in localStorage under a versioned key. Server-side
 * round-tripping would ruin the palette's "instant" feel and losing
 * recency on browser reset is a survivable UX cost — this is an
 * affordance, not a data contract.
 *
 * Entity rows are excluded on purpose (entities are already bookmarks
 * via the sidebar / URL); only static command ids are tracked.
 */

const STORAGE_KEY = 'nexus:palette:recent:v1';
const CAP = 5;

const canUseStorage = (): boolean => {
    try {
        return typeof window !== 'undefined' && !!window.localStorage;
    } catch {
        return false;
    }
};

export function getRecentCommandIds(): string[] {
    if (!canUseStorage()) return [];
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) return [];
        return parsed
            .filter((v): v is string => typeof v === 'string')
            .slice(0, CAP);
    } catch {
        return [];
    }
}

export function pushRecentCommand(commandId: string): void {
    if (!canUseStorage()) return;
    if (!commandId) return;
    try {
        const current = getRecentCommandIds();
        const next = [commandId, ...current.filter((id) => id !== commandId)].slice(
            0,
            CAP,
        );
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
    } catch {
        // Storage full / private mode / quota exceeded — silently no-op.
    }
}

export function clearRecentCommands(): void {
    if (!canUseStorage()) return;
    try {
        window.localStorage.removeItem(STORAGE_KEY);
    } catch {
        // Ignore.
    }
}
