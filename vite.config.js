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
            // The warning lands prefixed in the `composer run dev` output.
            console.warn(
                `[vite] Ignoring VITE_DEV_SERVER_URL="${tunnelUrl}" — not a valid URL.`,
            );
        }
    }

    // Allow override when port 5173 is already taken by another Vite (or any
    // other process). Tunnel still works as long as cloudflared points at the
    // matching local port.
    const localPort = Number(env.VITE_DEV_SERVER_PORT) || 5173;

    // Build the CORS allow-list from the public origins the browser will be
    // talking from. APP_URL covers the Laravel-side tunnel; tunnel.origin is
    // included so direct fetches against Vite (rare, but possible) pass too.
    // Falls back to permissive in tunnel mode only when APP_URL is unset, so
    // a brand-new clone with VITE_DEV_SERVER_URL but empty APP_URL still works.
    const corsOrigins = [env.APP_URL, tunnel?.origin].filter(Boolean);

    // Explicit allow-list of host headers Vite will accept in tunnel mode.
    // Listed eagerly so the value is the same object reference Vite eventually
    // freezes — see node_modules/vite/dist/node/chunks/node.js, the line that
    // does `Object.freeze([...config.server.allowedHosts])` during config
    // resolution. Order:
    //   - the tunnel's public hostname (preserved by cloudflared on forward),
    //   - `.trycloudflare.com` subdomain wildcard so a fresh quick-tunnel URL
    //     works without restarting Vite,
    //   - `localhost` / `127.0.0.1` for direct local hits and for any
    //     forwarder that does rewrite Host.
    // The security boundary stays `cors.origin` below — only browsers from the
    // configured public origins can actually consume the responses.
    const allowedHosts = tunnel
        ? [
              tunnel.hostname,
              '.trycloudflare.com',
              'localhost',
              '127.0.0.1',
              `localhost:${localPort}`,
              `127.0.0.1:${localPort}`,
          ]
        : undefined;

    if (tunnel) {
        // Loud startup banner so tunnel-mode activation (and the resolved
        // values) is visible inside the `composer run dev` concurrently
        // output (prefixed `vite:`). If you ever see a "Blocked request" 403,
        // copy/paste this banner so it's clear what Vite was actually told.
        console.info(
            `[vite] tunnel mode active — origin=${tunnel.origin}, port=${localPort}, allowedHosts=${JSON.stringify(allowedHosts)}, cors=${
                corsOrigins.length > 0 ? corsOrigins.join(',') : 'any'
            }`,
        );
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
                  port: localPort,
                  // Lock the port. If Vite drifts (5174 etc.) the tunnel breaks
                  // silently — better to fail loudly so the dev sets
                  // VITE_DEV_SERVER_PORT or frees the default port.
                  strictPort: true,
                  allowedHosts,
                  // Force every asset URL injected into Blade to use the
                  // public host instead of `[::1]:5173` / `localhost:5173`.
                  origin: tunnel.origin,
                  // Cross-origin asset loads from the Laravel-side tunnel.
                  cors:
                      corsOrigins.length > 0
                          ? { origin: corsOrigins }
                          : true,
                  hmr: {
                      host: tunnel.hostname,
                      protocol: tunnel.protocol === 'https:' ? 'wss' : 'ws',
                      clientPort: tunnel.protocol === 'https:' ? 443 : 80,
                  },
              }
            : undefined,
    };
});
