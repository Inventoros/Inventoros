<script setup>
import { useI18n } from 'vue-i18n';
import { SearchX } from '@lucide/vue';
import Button from './Button.vue';

// Pages own their filters, permissions, and create action. This component
// distinguishes a first-use empty state from a search that needs recovery.
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
    icon: { type: [Object, Function], default: null },
    filtered: { type: Boolean, default: false },
});
defineEmits(['clear']);
const { t } = useI18n();
</script>

<template>
    <div class="empty-state">
        <component v-if="filtered || icon" :is="filtered ? SearchX : icon" :size="24" aria-hidden="true" class="text-text-tertiary" />
        <div class="empty-state-copy" role="status">
            <h2 class="text-sm font-semibold text-text-primary">{{ filtered ? t('common.noResults') : title }}</h2>
            <p v-if="filtered || description" class="mt-2 text-sm text-text-secondary">{{ filtered ? t('components.emptyState.filteredHint') : description }}</p>
        </div>
        <div v-if="filtered || $slots.actions" class="empty-state-actions">
            <Button v-if="filtered" variant="secondary" @click="$emit('clear')">{{ t('common.clearFilters') }}</Button>
            <slot v-else name="actions" />
        </div>
    </div>
</template>

<style scoped>
.empty-state {
    display: flex;
    width: 100%;
    min-width: 0;
    flex-direction: column;
    align-items: center;
    gap: 16px;
    padding: 48px 20px;
    text-align: center;
}
.empty-state-copy {
    min-width: 0;
    max-width: 52ch;
    overflow-wrap: anywhere;
}
.empty-state-actions {
    display: flex;
    max-width: 100%;
    flex-wrap: wrap;
    justify-content: center;
    gap: 8px;
}
.empty-state-actions:empty {
    display: none;
}
.empty-state-actions :deep(> *) {
    min-height: 44px;
    height: auto;
    max-width: 100%;
    white-space: normal;
    overflow-wrap: anywhere;
    padding-block: 8px;
}
</style>
