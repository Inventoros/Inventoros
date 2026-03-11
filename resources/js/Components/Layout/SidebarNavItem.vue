<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    icon: {
        type: String,
        required: true,
    },
    label: {
        type: String,
        required: true,
    },
    activeRoutes: {
        type: Array,
        default: () => [],
    },
    activeColor: {
        type: String,
        default: 'primary',
    },
});

const isActive = computed(() => {
    if (props.activeRoutes.length > 0) {
        return props.activeRoutes.some(r => route().current(r));
    }
    return false;
});
</script>

<template>
    <Link
        :href="href"
        :class="[
            'flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all duration-150 text-sm',
            isActive
                ? 'bg-primary-600/20 text-primary-400 font-semibold'
                : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800'
        ]"
    >
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="icon" />
        </svg>
        <span class="font-medium">{{ label }}</span>
    </Link>
</template>
