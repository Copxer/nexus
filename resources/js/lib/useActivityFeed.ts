import type { ActivityEvent, PageProps } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Reactive activity-feed composable used by the AppLayout right rail
 * and the dedicated `/activity` page. Spec 019.
 *
 * Behaviour:
 *   - Initializes from a caller-provided seed (page-prop array OR the
 *     shared `activity.recent` Inertia prop) on mount.
 *   - Subscribes to the authenticated user's private channel
 *     `users.{id}.activity` and **prepends** broadcast events,
 *     deduplicating against any existing row with the same `id` and
 *     capping the local list to `limit` items so the rail/page never
 *     grows unbounded between navigations.
 *   - Re-syncs from the page props on every Inertia `navigate` —
 *     a fresh page load is the source of truth, so nothing leaks
 *     across pages.
 *   - Tracks `connected: Ref<boolean>` for the rail's reconnect pill
 *     (Echo flips it on `connected` / `disconnected`).
 *   - Cleans up the channel + listener on unmount.
 *
 * `window.Echo` is lazy — when the env vars aren't configured (or the
 * user is offline / Reverb isn't running) the composable still works,
 * just without realtime. Initial seed + page-load reads still surface.
 */
export function useActivityFeed(options: {
    /**
     * Source-of-truth seed for the local list. The composable reads
     * this on mount AND on every navigation; the explicit-prop-wins
     * vs shared-prop-fallback decision is made by the caller.
     */
    seed: () => ActivityEvent[];
    /** Hard cap on the rolling local list. Rail = 20, page = 100. */
    limit: number;
}) {
    const events = ref<ActivityEvent[]>([]);
    const connected = ref<boolean>(false);

    const reseed = () => {
        events.value = options.seed().slice(0, options.limit);
    };

    /** Drop-in dedup-and-prepend — broadcasts can arrive before the
     *  initial seed if Echo connects unusually fast, so we always
     *  filter by id. */
    const prepend = (incoming: ActivityEvent) => {
        const filtered = events.value.filter((e) => e.id !== incoming.id);
        events.value = [incoming, ...filtered].slice(0, options.limit);
    };

    let teardown: (() => void) | null = null;
    let unbindNavigate: (() => void) | null = null;

    onMounted(() => {
        reseed();

        // Re-seed on every Inertia page navigation. The shared prop
        // refreshes alongside the page, so this catches new events
        // that landed while the user was on a different page.
        unbindNavigate = router.on('navigate', () => reseed());

        if (typeof window === 'undefined' || !window.Echo) {
            return;
        }

        const page = usePage<PageProps>();
        const userId = page.props.auth?.user?.id;

        if (userId == null) {
            return;
        }

        const channel = window.Echo.private(`users.${userId}.activity`);
        channel.listen('.ActivityEventCreated', (payload: ActivityEvent) => {
            prepend(payload);
        });

        const connector = window.Echo.connector;
        const pusher = (connector as { pusher?: { connection?: { bind: (e: string, cb: () => void) => void; unbind: (e: string, cb: () => void) => void } } })?.pusher;

        const onConnect = () => (connected.value = true);
        const onDisconnect = () => (connected.value = false);

        if (pusher?.connection) {
            pusher.connection.bind('connected', onConnect);
            pusher.connection.bind('disconnected', onDisconnect);
            pusher.connection.bind('unavailable', onDisconnect);
            pusher.connection.bind('failed', onDisconnect);
        }

        teardown = () => {
            channel.stopListening('.ActivityEventCreated');
            window.Echo?.leave(`users.${userId}.activity`);
            if (pusher?.connection) {
                pusher.connection.unbind('connected', onConnect);
                pusher.connection.unbind('disconnected', onDisconnect);
                pusher.connection.unbind('unavailable', onDisconnect);
                pusher.connection.unbind('failed', onDisconnect);
            }
        };
    });

    onBeforeUnmount(() => {
        teardown?.();
        unbindNavigate?.();
    });

    return { events, connected };
}
