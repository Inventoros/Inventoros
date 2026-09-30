import { ref } from 'vue';
import { currentTheme, saveTheme } from '@/lib/theme';

// The saved choice, else the system preference.
const theme = ref(currentTheme());

export function useTheme() {
    const setTheme = (newTheme) => {
        theme.value = saveTheme(newTheme);
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
