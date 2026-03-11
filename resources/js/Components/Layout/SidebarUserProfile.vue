<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import { useI18n } from 'vue-i18n';

const props = defineProps({
    collapsed: {
        type: Boolean,
        default: false,
    },
});

const { t } = useI18n();
const page = usePage();

const user = computed(() => page.props.auth.user);
const userInitial = computed(() => user.value.name.charAt(0).toUpperCase());
</script>

<template>
    <div class="border-t border-slate-800 px-3 py-3">
        <!-- Collapsed: just avatar -->
        <div v-if="collapsed" class="flex justify-center">
            <Link
                :href="route('logout')"
                method="post"
                as="button"
                class="w-9 h-9 rounded-lg bg-primary-600 flex items-center justify-center hover:bg-red-500 transition"
                title="Logout"
            >
                <span class="text-sm font-bold text-white">{{ userInitial }}</span>
            </Link>
        </div>
        <!-- Expanded: full profile -->
        <div v-else class="flex items-center gap-3 px-3 py-2.5 rounded-lg bg-slate-800/50">
            <div class="flex-shrink-0">
                <div class="w-9 h-9 rounded-lg bg-primary-600 flex items-center justify-center">
                    <span class="text-sm font-bold text-white">{{ userInitial }}</span>
                </div>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-slate-200 truncate">{{ user.name }}</p>
                <p class="text-xs text-slate-500 truncate">{{ user.email }}</p>
            </div>
            <Link
                :href="route('logout')"
                method="post"
                as="button"
                class="flex-shrink-0 p-2 text-slate-500 hover:text-red-400 hover:bg-red-500/10 rounded-lg transition"
                title="Logout"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                </svg>
            </Link>
        </div>
    </div>
</template>
