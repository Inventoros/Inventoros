import { ref, watch } from 'vue';

const theme = ref(localStorage.getItem('theme') || 'dark');

export function useTheme() {
    const setTheme = (newTheme) => {
        theme.value = newTheme;
        localStorage.setItem('theme', newTheme);
    };

    const toggleTheme = () => {
        const newTheme = theme.value === 'dark' ? 'light' : 'dark';
        setTheme(newTheme);
    };

    const isDark = () => theme.value === 'dark';
    const isLight = () => theme.value === 'light';

    return {
        theme,
        setTheme,
        toggleTheme,
        isDark,
        isLight,
    };
}
