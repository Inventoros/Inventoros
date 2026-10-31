<script setup>
import { useI18n } from 'vue-i18n';
import { displayDateTime } from '@/lib/dates';
defineProps({ review: Object });
const { t } = useI18n();
</script>

<template>
    <div v-if="review" class="mt-3 space-y-1 border-t border-border-subtle pt-3 text-xs text-text-secondary">
        <p class="font-semibold">{{ t(`auditReview.${review.decision}`) }}</p>
        <p>{{ review.reviewer_name }} · {{ displayDateTime(review.created_at) }}</p>
        <p v-if="review.notes" class="whitespace-pre-wrap break-words">{{ review.notes }}</p>
    </div>
</template>
