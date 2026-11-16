<script setup>
import Button from '@/Components/ui/Button.vue';
import { useI18n } from 'vue-i18n';

defineProps({
    label: { type: String, required: true },
    cancelHref: { type: String, required: true },
    processing: { type: Boolean, default: false },
});

const { t } = useI18n();
</script>

<template>
    <div
        class="product-form-actions sticky bottom-0 z-20 mt-6 flex flex-wrap items-stretch justify-end gap-2 rounded-t-lg border border-border-subtle bg-surface-raised p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] shadow-sm"
    >
        <Button
            variant="secondary"
            as="Link"
            :href="cancelHref"
            class="h-auto min-h-11 flex-1 whitespace-normal py-2.5 sm:h-10 sm:min-h-10 sm:flex-none sm:py-0"
        >
            {{ t('common.cancel') }}
        </Button>
        <Button
            type="submit"
            variant="default"
            :loading="processing"
            :disabled="processing"
            class="h-auto min-h-11 flex-1 whitespace-normal py-2.5 sm:h-10 sm:min-h-10 sm:flex-none sm:py-0"
        >
            {{ label }}
        </Button>
    </div>
</template>

<style>
/* The document scrolls on these forms. Reserve space for the sticky chrome
   during native validation and keyboard focus, including the phone safe area. */
html:has(.product-form-actions) {
    scroll-padding-block-start: 7rem;
    scroll-padding-block-end: calc(7rem + env(safe-area-inset-bottom));
}
</style>
