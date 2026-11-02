<script setup>
import { computed } from 'vue';
import DialogSurface from '@/Components/DialogSurface.vue';

defineOptions({ inheritAttrs: false });
const props = defineProps({
    show: { type: Boolean, default: false },
    maxWidth: { type: String, default: '2xl' },
    closeable: { type: Boolean, default: true },
});
const emit = defineEmits(['close']);
const close = () => { if (props.closeable) emit('close'); };
const maxWidthClass = computed(() => {
    return {
        sm: 'sm:max-w-sm',
        md: 'sm:max-w-md',
        lg: 'sm:max-w-lg',
        xl: 'sm:max-w-xl',
        '2xl': 'sm:max-w-2xl',
    }[props.maxWidth];
});
</script>

<template>
    <DialogSurface :show="show" :closeable="closeable" :label="$attrs['aria-label']" @close="close">
        <div class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0" scroll-region>
            <div class="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75" @click="close" />
            <div class="relative mb-6 overflow-hidden rounded-lg bg-white shadow-xl sm:mx-auto sm:w-full dark:bg-gray-800" :class="maxWidthClass">
                <slot />
            </div>
        </div>
    </DialogSurface>
</template>
