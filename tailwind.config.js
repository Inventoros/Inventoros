import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

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
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Custom dark theme colors inspired by the design
                dark: {
                    bg: '#0a0e27',      // Deep navy background
                    card: '#131837',    // Slightly lighter card background
                    border: '#1e2541',  // Subtle border
                },
                primary: {
                    50: '#f0fdff',
                    100: '#ccf7fe',
                    200: '#99eefd',
                    300: '#5ce0fa',
                    400: '#22c9f0',   // Main cyan/teal
                    500: '#06aed6',
                    600: '#088ab3',
                    700: '#0d6e91',
                    800: '#145975',
                    900: '#154a63',
                },
                accent: {
                    purple: '#8b5cf6',  // Purple accent
                    pink: '#ec4899',    // Pink accent
                    cyan: '#22c9f0',    // Cyan accent
                },
            },
        },
    },

    plugins: [forms],
};
