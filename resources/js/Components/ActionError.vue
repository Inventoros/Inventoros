<script setup>
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps({ errors: { type: Object, default: () => ({}) } });
const messages = computed(() => [...new Set(Object.values(props.errors).flat().filter(Boolean))]);
const summary = ref(null);

// Keep rejected modal actions visible and make the reason reachable without
// requiring a user to discover an off-screen page-level validation message.
watch(messages, async (values) => {
    if (!values.length) return;
    await nextTick();
    summary.value?.focus();
});
</script>

<template>
    <div v-if="messages.length" ref="summary" role="alert" tabindex="-1"
        class="mb-4 rounded-lg border border-status-danger/30 bg-status-danger-soft p-3 text-sm text-status-danger ds-focus-ring">
        <p v-for="message in messages" :key="message">{{ message }}</p>
    </div>
</template>
