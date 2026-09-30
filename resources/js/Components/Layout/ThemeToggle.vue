<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import { applyTheme, followSystemTheme, saveTheme } from '@/lib/theme';

const { t } = useI18n();

const isDark = ref(true);

const toggleTheme = () => {
    isDark.value = saveTheme(isDark.value ? 'light' : 'dark') === 'dark';
};

// The saved choice, else the system's light or dark setting (followed live
// until the user picks one here).
let stopFollowing = () => {};
onMounted(() => {
    isDark.value = applyTheme() === 'dark';
    stopFollowing = followSystemTheme((theme) => { isDark.value = theme === 'dark'; });
});
onBeforeUnmount(() => stopFollowing());
</script>

<template>
    <button
        @click="toggleTheme"
        class="p-2 text-gray-500 hover:text-brand dark:text-slate-400 dark:hover:text-brand hover:bg-gray-100 dark:hover:bg-surface-raised rounded-lg transition"
        :title="isDark ? t('components.themeToggle.switchToLight') : t('components.themeToggle.switchToDark')"
    >
        <!-- Sun icon (shown when IN dark mode) -->
        <svg v-if="isDark" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
        </svg>
        <!-- Moon icon (shown when IN light mode) -->
        <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
        </svg>
    </button>
</template>
