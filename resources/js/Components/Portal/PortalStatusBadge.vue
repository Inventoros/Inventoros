<script setup>
import Badge from '@/Components/ui/Badge.vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    status: { type: String, default: null },
});

const { t, te } = useI18n();

const VARIANTS = {
    pending: 'warning',
    processing: 'info',
    shipped: 'brand',
    delivered: 'success',
    cancelled: 'danger',
    approved: 'info',
    received: 'brand',
    completed: 'success',
    rejected: 'danger',
};

const label = computed(() => {
    const key = `portal.statuses.${props.status}`;
    return te(key) ? t(key) : props.status;
});
</script>

<template>
    <Badge v-if="status" :variant="VARIANTS[status] || 'neutral'" size="sm" dot>
        {{ label }}
    </Badge>
    <span v-else class="text-text-tertiary">-</span>
</template>
