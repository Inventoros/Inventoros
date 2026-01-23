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

const colorClasses = {
    primary: 'bg-primary-400/10 text-primary-400 border border-primary-400/30',
    pink: 'bg-pink-400/10 text-pink-400 border border-pink-400/30',
    emerald: 'bg-emerald-400/10 text-emerald-400 border border-emerald-400/30',
    amber: 'bg-amber-400/10 text-amber-400 border border-amber-400/30',
    purple: 'bg-accent-purple/10 text-accent-purple border border-accent-purple/30',
    orange: 'bg-orange-400/10 text-orange-400 border border-orange-400/30',
    cyan: 'bg-cyan-400/10 text-cyan-400 border border-cyan-400/30',
    teal: 'bg-teal-400/10 text-teal-400 border border-teal-400/30',
    blue: 'bg-blue-400/10 text-blue-400 border border-blue-400/30',
    indigo: 'bg-indigo-400/10 text-indigo-400 border border-indigo-400/30',
    green: 'bg-green-400/10 text-green-400 border border-green-400/30',
    yellow: 'bg-yellow-400/10 text-yellow-400 border border-yellow-400/30',
};

const isActive = computed(() => {
    if (props.activeRoutes.length > 0) {
        return props.activeRoutes.some(r => route().current(r));
    }
    return false;
});

const activeClass = computed(() => colorClasses[props.activeColor] || colorClasses.primary);
</script>

<template>
    <Link
        :href="href"
        :class="[
            'flex items-center gap-3 px-3 py-3 rounded-lg transition-all duration-150',
            isActive
                ? activeClass
                : 'text-gray-400 hover:text-gray-200 hover:bg-dark-bg/50'
        ]"
    >
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="icon" />
        </svg>
        <span class="font-medium">{{ label }}</span>
    </Link>
</template>
