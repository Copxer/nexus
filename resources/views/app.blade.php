<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        {{-- Spec 036 — pre-paint theme application. Runs before any
             CSS resolves so a light-mode user doesn't briefly flash
             the dark palette while Vue boots. Reads the persisted
             `users.theme` value if authenticated (defaults to `dark`).
             `AppLayout.vue` re-applies the same logic on mount + on
             every page navigation, so a runtime toggle (Settings)
             still works without a refresh. --}}
        <script>
            (function () {
                var theme = '{{ Auth::user()?->theme ?? 'dark' }}';
                var wantDark = theme === 'dark'
                    || (theme === 'system'
                        && window.matchMedia('(prefers-color-scheme: dark)').matches);
                if (wantDark) document.documentElement.classList.add('dark');
            })();
        </script>

        <!-- Favicons (PNG raster, SVG vector, .ico fallback, Apple touch, web manifest) -->
        <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
        <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
        <link rel="shortcut icon" href="/favicon.ico" />
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
        <link rel="manifest" href="/site.webmanifest" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.ts', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="min-h-screen font-sans text-text-primary antialiased">
        @inertia
    </body>
</html>
