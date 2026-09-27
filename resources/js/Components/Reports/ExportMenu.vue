<script setup>
import Button from '@/Components/ui/Button.vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Download } from 'lucide-vue-next';

// Download links for a report route in each server-rendered format. The
// current filters travel with the link so the file matches what is on screen.
const props = defineProps({
    routeName: { type: String, required: true },
    params: { type: Object, default: () => ({}) },
    // Optional alternate breakdown (e.g. "category"), passed as ?group=.
    group: { type: String, default: null },
    size: { type: String, default: 'sm' },
});

const { t } = useI18n();

const formats = [
    { key: 'csv', label: 'reports.export.csv' },
    { key: 'xlsx', label: 'reports.export.xlsx' },
    { key: 'pdf', label: 'reports.export.pdf' },
];

const hrefFor = (format) => {
    const query = { ...props.params, export: format };
    if (props.group) query.group = props.group;
    return route(props.routeName, query);
};

const links = computed(() => formats.map((f) => ({ ...f, href: hrefFor(f.key) })));
</script>

<template>
    <div class="inline-flex items-center gap-1" role="group" :aria-label="t('reports.export.label')">
        <span class="inline-flex items-center gap-1 pr-1 text-xs text-text-tertiary">
            <Download :size="14" />
            {{ t('reports.export.label') }}
        </span>
        <Button
            v-for="link in links"
            :key="link.key"
            variant="secondary"
            :size="size === 'sm' ? 'xs' : size"
            as="a"
            :href="link.href"
        >
            {{ t(link.label) }}
        </Button>
    </div>
</template>
