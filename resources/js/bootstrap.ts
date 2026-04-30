import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        axios: typeof axios;
        Pusher: typeof Pusher;
        Echo: Echo<'reverb'>;
    }
}

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

window.Pusher = Pusher;

const reverbHost = import.meta.env.VITE_REVERB_HOST;
const reverbKey = import.meta.env.VITE_REVERB_APP_KEY;
const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'http';
const port = import.meta.env.VITE_REVERB_PORT ?? '8080';

// Spec 019 debug — gate behind `localStorage.NEXUS_DEBUG_ECHO = '1'`
// (or the build-time `VITE_REVERB_DEBUG=true` env). Off by default so
// production / regular dev is silent. Set the localStorage flag once
// in DevTools, refresh, and you get the full lifecycle.
const isTruthy = (v: unknown): boolean =>
    v === true || v === '1' || v === 'true';
const debugFlag =
    isTruthy(import.meta.env.VITE_REVERB_DEBUG) ||
    (typeof window !== 'undefined' &&
        isTruthy(window.localStorage?.getItem('NEXUS_DEBUG_ECHO')));

const log = (...args: unknown[]) => {
    if (debugFlag) console.log('[echo]', ...args);
};

// Always emit one boot-status line, even with the verbose flag off, so
// the user can immediately see whether the VITE_REVERB_* env reached the
// browser without having to toggle anything in DevTools.
console.info('[echo] boot env', {
    host: reverbHost || '(missing)',
    port,
    scheme,
    keyPresent: !!reverbKey,
    debugFlag,
});

if (reverbHost && reverbKey) {
    if (debugFlag) {
        // pusher-js exposes its own verbose logging — pipe through the
        // same flag so a single localStorage toggle covers both layers.
        Pusher.logToConsole = true;
    }

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: reverbKey,
        wsHost: reverbHost,
        wsPort: Number(port),
        wssPort: Number(port),
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    log('Echo instance created', {
        wsHost: reverbHost,
        wsPort: Number(port),
        forceTLS: scheme === 'https',
    });

    // Connection-level lifecycle: `connecting` → `connected` (or
    // `unavailable` / `failed`). Useful for spotting tunnel handshake
    // issues that never reach the channel layer.
    type PusherConnection = {
        bind: (e: string, cb: (data?: unknown) => void) => void;
        state?: string;
    };
    const pusher = (
        window.Echo.connector as { pusher?: { connection?: PusherConnection } }
    )?.pusher;

    pusher?.connection?.bind('state_change', (states: unknown) => {
        log('connection state', states);
    });
    pusher?.connection?.bind('error', (err: unknown) => {
        log('connection error', err);
    });
} else {
    // Loud warning — without these the rail will always show OFFLINE.
    console.warn(
        '[echo] NOT initialized — VITE_REVERB_HOST or VITE_REVERB_APP_KEY missing in the bundle. ' +
            'Restart `composer run dev` after editing .env so Vite picks up the new values.',
    );
}
