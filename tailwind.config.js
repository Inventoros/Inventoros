import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import animate from 'tailwindcss-animate';

/**
 * The Linear-inspired token layer below uses CSS variables defined in
 * resources/css/design-tokens.css. Components should prefer the semantic
 * Tailwind utilities (bg-surface-base, text-text-primary, border-border, etc.)
 * over the legacy palette, which is kept for backwards compatibility while
 * pages migrate.
 */

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    darkMode: 'class',

    theme: {
        extend: {
            fontFamily: {
                sans: [
                    'Inter',
                    'Figtree',
                    ...defaultTheme.fontFamily.sans,
                ],
                mono: [
                    'JetBrains Mono',
                    ...defaultTheme.fontFamily.mono,
                ],
            },
            letterSpacing: {
                tight: '-0.01em',
                tighter: '-0.02em',
            },
            colors: {
                // === Linear-inspired semantic tokens (preferred) ===
                surface: {
                    canvas: 'hsl(var(--surface-canvas) / <alpha-value>)',
                    base: 'hsl(var(--surface-base) / <alpha-value>)',
                    raised: 'hsl(var(--surface-raised) / <alpha-value>)',
                    overlay: 'hsl(var(--surface-overlay) / <alpha-value>)',
                    sunken: 'hsl(var(--surface-sunken) / <alpha-value>)',
                },
                border: {
                    DEFAULT: 'hsl(var(--border-subtle) / <alpha-value>)',
                    subtle: 'hsl(var(--border-subtle) / <alpha-value>)',
                    strong: 'hsl(var(--border-strong) / <alpha-value>)',
                },
                text: {
                    primary: 'hsl(var(--text-primary) / <alpha-value>)',
                    secondary: 'hsl(var(--text-secondary) / <alpha-value>)',
                    tertiary: 'hsl(var(--text-tertiary) / <alpha-value>)',
                    disabled: 'hsl(var(--text-disabled) / <alpha-value>)',
                },
                brand: {
                    DEFAULT: 'hsl(var(--accent) / <alpha-value>)',
                    hover: 'hsl(var(--accent-hover) / <alpha-value>)',
                    soft: 'hsl(var(--accent-soft) / <alpha-value>)',
                    foreground: 'hsl(var(--accent-foreground) / <alpha-value>)',
                },
                status: {
                    success: 'hsl(var(--status-success) / <alpha-value>)',
                    'success-soft': 'hsl(var(--status-success-soft) / <alpha-value>)',
                    warning: 'hsl(var(--status-warning) / <alpha-value>)',
                    'warning-soft': 'hsl(var(--status-warning-soft) / <alpha-value>)',
                    danger: 'hsl(var(--status-danger) / <alpha-value>)',
                    'danger-soft': 'hsl(var(--status-danger-soft) / <alpha-value>)',
                    info: 'hsl(var(--status-info) / <alpha-value>)',
                    'info-soft': 'hsl(var(--status-info-soft) / <alpha-value>)',
                },
                ring: 'hsl(var(--ring) / <alpha-value>)',

                // === Legacy palette (kept for unmigrated pages) ===
                sidebar: {
                    bg: '#0f172a',
                    hover: '#1e293b',
                    border: '#1e293b',
                    active: '#1e293b',
                },
                dark: {
                    bg: '#0f172a',
                    card: '#1e293b',
                    border: '#334155',
                },
                primary: {
                    50: '#eff6ff',
                    100: '#dbeafe',
                    200: '#bfdbfe',
                    300: '#93c5fd',
                    400: '#60a5fa',
                    500: '#3b82f6',
                    600: '#2563eb',
                    700: '#1d4ed8',
                    800: '#1e40af',
                    900: '#1e3a8a',
                    950: '#172554',
                },
                accent: {
                    purple: '#8b5cf6',
                    pink: '#ec4899',
                    cyan: '#22c9f0',
                },
            },
            borderRadius: {
                sm: 'var(--radius-sm)',
                md: 'var(--radius-md)',
                lg: 'var(--radius-lg)',
                xl: 'var(--radius-xl)',
            },
            boxShadow: {
                xs: 'var(--shadow-sm)',
                sm: 'var(--shadow-sm)',
                md: 'var(--shadow-md)',
                lg: 'var(--shadow-lg)',
                'card': '0 1px 3px 0 rgb(0 0 0 / 0.04), 0 1px 2px -1px rgb(0 0 0 / 0.04)',
                'card-hover': '0 4px 6px -1px rgb(0 0 0 / 0.06), 0 2px 4px -2px rgb(0 0 0 / 0.06)',
            },
            keyframes: {
                'fade-in': {
                    from: { opacity: '0' },
                    to: { opacity: '1' },
                },
                'slide-down': {
                    from: { opacity: '0', transform: 'translateY(-4px)' },
                    to: { opacity: '1', transform: 'translateY(0)' },
                },
            },
            animation: {
                'fade-in': 'fade-in 150ms ease-out',
                'slide-down': 'slide-down 150ms ease-out',
            },
        },
    },

    plugins: [forms, animate],
};
