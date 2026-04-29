import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig(({ mode }) => {
    // Pull every key (not just VITE_*) so we can read APP_URL too.
    const env = loadEnv(mode, process.cwd(), '');

    // Tunnel mode kicks in when VITE_DEV_SERVER_URL points at a public host —
    // typically a `cloudflared tunnel --url http://localhost:5173` quick-tunnel
    // URL. See the "Browsing the dev UI through a tunnel" section in README.md.
    const tunnelUrl = env.VITE_DEV_SERVER_URL?.trim() || null;
    let tunnel = null;
    if (tunnelUrl) {
        try {
            tunnel = new URL(tunnelUrl);
        } catch {
            // Bad URL → fall back to local mode rather than crash Vite at boot.
            // The console message lands inside the `composer run dev` output.
            console.warn(
                `[vite] Ignoring VITE_DEV_SERVER_URL="${tunnelUrl}" — not a valid URL.`,
            );
        }
    }

    return {
        plugins: [
            laravel({
                input: 'resources/js/app.ts',
                refresh: true,
            }),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
        ],
        server: tunnel
            ? {
                  // Bind every interface so the cloudflared sidecar can reach Vite.
                  host: '0.0.0.0',
                  // Lock the port. If Vite drifts to 5174 the tunnel breaks
                  // silently — better to fail loudly so the dev frees 5173.
                  port: 5173,
                  strictPort: true,
                  // Force every asset URL injected into Blade to use the public
                  // host instead of `[::1]:5173` / `localhost:5173`.
                  origin: tunnel.origin,
                  // The Laravel app is on a different (cross-origin) tunnel, so
                  // CORS must allow the browser to load Vite's assets from there.
                  cors: true,
                  hmr: {
                      host: tunnel.hostname,
                      protocol: tunnel.protocol === 'https:' ? 'wss' : 'ws',
                      clientPort: tunnel.protocol === 'https:' ? 443 : 80,
                  },
              }
            : undefined,
    };
});
