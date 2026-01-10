<script setup>
defineProps({
    show: {
        type: Boolean,
        default: false
    },
    title: {
        type: String,
        required: true
    },
    loading: {
        type: Boolean,
        default: false
    },
    maxWidth: {
        type: String,
        default: 'md' // sm, md, lg, xl, 2xl
    }
});

const emit = defineEmits(['close']);

const close = () => {
    emit('close');
};

const maxWidthClass = {
    sm: 'max-w-sm',
    md: 'max-w-md',
    lg: 'max-w-lg',
    xl: 'max-w-xl',
    '2xl': 'max-w-2xl',
};
</script>

<template>
    <Teleport to="body">
        <div
            v-if="show"
            class="fixed inset-0 z-50 overflow-y-auto"
            @click="close"
        >
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>

                <div
                    :class="['relative bg-white dark:bg-dark-card rounded-lg shadow-xl w-full p-6', maxWidthClass[maxWidth]]"
                    @click.stop
                >
                    <!-- Header -->
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                            {{ title }}
                        </h3>
                        <button
                            @click="close"
                            class="text-gray-500 dark:text-gray-400 hover:text-gray-200"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Content -->
                    <div class="space-y-4">
                        <slot></slot>
                    </div>

                    <!-- Actions -->
                    <div class="flex gap-3 justify-end mt-6">
                        <slot name="actions">
                            <button
                                type="button"
                                @click="close"
                                class="px-4 py-2 bg-dark-bg text-gray-600 dark:text-gray-300 rounded-md hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                            >
                                Cancel
                            </button>
                        </slot>
                    </div>

                    <!-- Loading Overlay -->
                    <div
                        v-if="loading"
                        class="absolute inset-0 bg-white/50 dark:bg-dark-card/50 flex items-center justify-center rounded-lg"
                    >
                        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-400"></div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
