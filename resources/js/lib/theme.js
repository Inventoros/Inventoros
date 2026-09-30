// Light or dark: the user's saved choice ('theme' in localStorage), else the
// system preference (prefers-color-scheme). The app used to start dark for
// anyone who had not chosen.

export const THEME_KEY = 'theme';

export function resolveTheme(saved, prefersDark) {
    if (saved === 'dark' || saved === 'light') return saved;
    return prefersDark ? 'dark' : 'light';
}

function readSaved() {
    try {
        return window.localStorage.getItem(THEME_KEY);
    } catch {
        return null;
    }
}

function systemQuery() {
    return typeof window !== 'undefined' && window.matchMedia
        ? window.matchMedia('(prefers-color-scheme: dark)')
        : null;
}

export function currentTheme() {
    return resolveTheme(readSaved(), Boolean(systemQuery()?.matches));
}

export function applyTheme(theme = currentTheme()) {
    document.documentElement.classList.toggle('dark', theme === 'dark');
    return theme;
}

export function saveTheme(theme) {
    try {
        window.localStorage.setItem(THEME_KEY, theme);
    } catch {
        // Storage blocked: the choice lasts for this page only.
    }
    return applyTheme(theme);
}

// Follow the system while the user has not chosen. Returns a stop function.
export function followSystemTheme(onChange = () => {}) {
    const query = systemQuery();
    if (!query) return () => {};
    const listener = () => {
        if (readSaved() === null) onChange(applyTheme());
    };
    query.addEventListener?.('change', listener);
    return () => query.removeEventListener?.('change', listener);
}
