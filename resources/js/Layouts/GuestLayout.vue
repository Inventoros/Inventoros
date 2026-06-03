<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center bg-surface-canvas pt-6 sm:justify-center sm:pt-0"
    >
        <div>
            <Link href="/">
                <ApplicationLogo class="h-20 w-20 fill-current text-text-tertiary" />
            </Link>
        </div>

        <div
            class="mt-6 w-full overflow-hidden border border-border-subtle bg-surface-base px-6 py-4 shadow-sm sm:max-w-md sm:rounded-lg"
        >
            <slot />
        </div>
    </div>
</template>
