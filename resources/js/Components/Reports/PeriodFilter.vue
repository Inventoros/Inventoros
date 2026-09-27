<script setup>
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

// Date range filter shared by the period-based report pages. Extra query
// values (e.g. a grouping) can be passed through with `extra`.
const props = defineProps({
    routeName: { type: String, required: true },
    filters: { type: Object, required: true },
    extra: { type: Object, default: () => ({}) },
});

const { t } = useI18n();

const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');

const apply = () => {
    router.get(route(props.routeName), {
        ...props.extra,
        date_from: dateFrom.value,
        date_to: dateTo.value,
    }, { preserveState: true, preserveScroll: true });
};

const inputClass =
    'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary ds-focus-ring';
</script>

<template>
    <Card class="mt-6">
        <form class="grid grid-cols-1 gap-4 md:grid-cols-3" @submit.prevent="apply">
            <div>
                <label :for="`${routeName}-from`" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('reports.salesAnalysis.dateFrom') }}</label>
                <input :id="`${routeName}-from`" v-model="dateFrom" type="date" :class="inputClass" />
            </div>
            <div>
                <label :for="`${routeName}-to`" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('reports.salesAnalysis.dateTo') }}</label>
                <input :id="`${routeName}-to`" v-model="dateTo" type="date" :class="inputClass" />
            </div>
            <div class="flex items-end">
                <Button type="submit" variant="default" size="sm">{{ t('reports.salesAnalysis.apply') }}</Button>
            </div>
        </form>
    </Card>
</template>
