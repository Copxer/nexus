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

// Spec 019 — Echo singleton wired against Reverb. The composable in
// `lib/useActivityFeed.ts` consumes it via `window.Echo`. Reverb speaks
// the Pusher protocol, so we configure the `reverb` broadcaster (which
// internally uses `pusher-js`) and expose `window.Pusher` so the
// laravel-echo lookup succeeds at runtime.
//
// `enabledTransports: ['ws', 'wss']` skips Pusher's HTTP fallback —
// Reverb only speaks websockets. `forceTLS` is derived from the
// configured scheme so http/wss don't get accidentally mismatched
// behind a tunnel.
window.Pusher = Pusher;

const reverbHost = import.meta.env.VITE_REVERB_HOST;
const reverbKey = import.meta.env.VITE_REVERB_APP_KEY;

if (reverbHost && reverbKey) {
    const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'http';
    const port = import.meta.env.VITE_REVERB_PORT ?? '8080';
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: reverbKey,
        wsHost: reverbHost,
        wsPort: Number(port),
        wssPort: Number(port),
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
