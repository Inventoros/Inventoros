<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();

const user = computed(() => page.props.auth.user);
const userInitial = computed(() => user.value.name.charAt(0).toUpperCase());
</script>

<template>
    <div class="border-t border-dark-border px-4 py-4">
        <div class="flex items-center gap-3 px-3 py-3 rounded-lg bg-dark-bg/30">
            <div class="flex-shrink-0">
                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center ring-2 ring-primary-400/30">
                    <span class="text-sm font-bold text-white">{{ userInitial }}</span>
                </div>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-gray-100 truncate">{{ user.name }}</p>
                <p class="text-xs text-gray-400 truncate">{{ user.email }}</p>
            </div>
            <Link
                :href="route('logout')"
                method="post"
                as="button"
                class="flex-shrink-0 p-2 text-gray-500 hover:text-red-400 hover:bg-red-400/10 rounded-lg transition"
                title="Logout"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                </svg>
            </Link>
        </div>
    </div>
</template>
