<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <button
        :type="type"
        class="inline-flex items-center rounded-md border border-border-subtle bg-surface-base px-4 py-2 text-xs font-semibold uppercase tracking-widest text-text-secondary shadow-sm transition duration-150 ease-in-out hover:bg-surface-overlay ds-focus-ring disabled:opacity-25"
    >
        <slot />
    </button>
</template>
