import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            // Tokens locked from specs/visual-reference.md (sourced from nexus-dashboard.png).
            colors: {
                background: {
                    base: '#020617',
                    panel: 'rgba(15, 23, 42, 0.72)',
                    'panel-hover': 'rgba(30, 41, 59, 0.85)',
                },
                border: {
                    subtle: 'rgba(148, 163, 184, 0.16)',
                    active: 'rgba(56, 189, 248, 0.5)',
                },
                text: {
                    primary: '#F8FAFC',
                    secondary: '#CBD5E1',
                    muted: '#64748B',
                },
                accent: {
                    blue: '#38BDF8',
                    cyan: '#22D3EE',
                    purple: '#8B5CF6',
                    magenta: '#D946EF',
                },
                status: {
                    success: '#22C55E',
                    warning: '#F59E0B',
                    danger: '#EF4444',
                    info: '#3B82F6',
                },
            },

            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                mono: ['"JetBrains Mono"', ...defaultTheme.fontFamily.mono],
            },

            backgroundImage: {
                'app-gradient':
                    'linear-gradient(135deg, #020617 0%, #0B1220 100%)',
            },

            backdropBlur: {
                xs: '2px',
            },

            boxShadow: {
                // Reserved for active/hovered/critical states only — never neutral.
                'glow-cyan': '0 0 24px rgba(34, 211, 238, 0.45)',
                'glow-purple': '0 0 24px rgba(139, 92, 246, 0.45)',
                'glow-magenta': '0 0 24px rgba(217, 70, 239, 0.45)',
                'glow-success': '0 0 24px rgba(34, 197, 94, 0.45)',
                'glow-danger': '0 0 24px rgba(239, 68, 68, 0.55)',
                panel: '0 25px 50px -12px rgba(0, 0, 0, 0.55)',
            },
        },
    },

    plugins: [forms],
};
