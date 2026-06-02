<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Plus, Trash2, ChevronUp, ChevronDown, RefreshCw } from 'lucide-vue-next';
import axios from 'axios';

const { t } = useI18n();

const props = defineProps({
    dataSources: Object,
});

const form = useForm({
    name: '',
    description: '',
    data_source: '',
    columns: [],
    filters: [],
    sort: { field: '', direction: 'asc' },
    chart_type: null,
    chart_field: null,
    is_shared: false,
});

// Preview state
const previewData = ref(null);
const previewLoading = ref(false);
const previewError = ref('');

// Available columns based on selected data source (as array of {key, label, type})
const availableColumns = computed(() => {
    if (!form.data_source || !props.dataSources[form.data_source]) return [];
    const cols = props.dataSources[form.data_source].columns;
    if (!cols) return [];
    return Object.entries(cols).map(([key, config]) => ({
        key,
        label: config.label,
        type: config.type,
    }));
});

// Reset columns and filters when data source changes
watch(() => form.data_source, () => {
    form.columns = [];
    form.filters = [];
    form.sort = { field: '', direction: 'asc' };
    form.chart_type = null;
    form.chart_field = null;
    previewData.value = null;
});

// Toggle column selection
const toggleColumn = (columnKey) => {
    const idx = form.columns.indexOf(columnKey);
    if (idx >= 0) {
        form.columns.splice(idx, 1);
    } else {
        form.columns.push(columnKey);
    }
};

const isColumnSelected = (columnKey) => form.columns.includes(columnKey);

// Move column position
const moveColumn = (index, direction) => {
    const newIndex = index + direction;
    if (newIndex < 0 || newIndex >= form.columns.length) return;
    const temp = form.columns[index];
    form.columns[index] = form.columns[newIndex];
    form.columns[newIndex] = temp;
};

// Select all / deselect all columns
const selectAllColumns = () => {
    form.columns = availableColumns.value.map(c => c.key);
};
const deselectAllColumns = () => {
    form.columns = [];
};

// Filter management
const addFilter = () => {
    form.filters.push({ field: '', operator: 'eq', value: '' });
};
const removeFilter = (index) => {
    form.filters.splice(index, 1);
};

const operators = [
    { value: 'eq', label: t('reportBuilder.operators.equals') },
    { value: 'neq', label: t('reportBuilder.operators.notEquals') },
    { value: 'gt', label: t('reportBuilder.operators.greaterThan') },
    { value: 'lt', label: t('reportBuilder.operators.lessThan') },
    { value: 'gte', label: t('reportBuilder.operators.greaterOrEqual') },
    { value: 'lte', label: t('reportBuilder.operators.lessOrEqual') },
    { value: 'contains', label: t('reportBuilder.operators.contains') },
    { value: 'starts_with', label: t('reportBuilder.operators.startsWith') },
    { value: 'ends_with', label: t('reportBuilder.operators.endsWith') },
    { value: 'is_null', label: t('reportBuilder.operators.isEmpty') },
    { value: 'is_not_null', label: t('reportBuilder.operators.isNotEmpty') },
];

const noValueOperators = ['is_null', 'is_not_null'];

const chartTypes = [
    { value: null, label: t('reportBuilder.chartNone') },
    { value: 'bar', label: t('reportBuilder.chartBar') },
    { value: 'line', label: t('reportBuilder.chartLine') },
    { value: 'pie', label: t('reportBuilder.chartPie') },
];

// Get column type for input rendering
const getColumnType = (fieldKey) => {
    const col = availableColumns.value.find(c => c.key === fieldKey);
    return col?.type || 'string';
};

// Preview
const previewReport = async () => {
    if (!form.data_source || form.columns.length === 0) return;
    previewLoading.value = true;
    previewError.value = '';
    previewData.value = null;

    try {
        const payload = {
            data_source: form.data_source,
            columns: form.columns,
            filters: form.filters.filter(f => f.field && f.operator),
            sort: form.sort.field ? form.sort : null,
        };
        const response = await axios.post(route('reports.builder.preview'), payload);
        previewData.value = response.data;
    } catch (err) {
        previewError.value = err.response?.data?.error || t('reportBuilder.previewError');
    } finally {
        previewLoading.value = false;
    }
};

// Save
const saveReport = () => {
    const data = { ...form.data() };
    // Clean filters
    data.filters = data.filters.filter(f => f.field && f.operator);
    if (data.filters.length === 0) data.filters = null;
    if (!data.sort?.field) data.sort = null;
    if (!data.chart_type) {
        data.chart_type = null;
        data.chart_field = null;
    }
    form.transform(() => data).post(route('reports.builder.store'));
};

// Column label helper
const columnLabel = (key) => {
    const col = availableColumns.value.find(c => c.key === key);
    return col?.label || key;
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head :title="t('reportBuilder.createReport')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">Workspace</span>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('reports.builder.index')" class="text-text-tertiary hover:text-text-primary">{{ t('reports.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('reportBuilder.createReport') }}</span>
            </div>
        </template>

        <PageHeader :title="t('reportBuilder.createReport')" :description="t('reportBuilder.createSubtitle')">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('reports.builder.index')">
                    <ArrowLeft :size="14" />
                    {{ t('reportBuilder.backToList') }}
                </Button>
            </template>
        </PageHeader>

        <div class="mx-auto mt-6 max-w-4xl space-y-4">

            <!-- Report Info -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('reportBuilder.sections.info') }}</h3></div>
                <div class="space-y-4 p-5">
                    <div>
                        <label :class="fieldLabel">{{ t('reportBuilder.fields.name') }} <span class="text-status-danger">*</span></label>
                        <input
                            v-model="form.name"
                            type="text"
                            maxlength="255"
                            :class="fieldInput"
                            :placeholder="t('reportBuilder.placeholders.name')"
                        />
                        <p v-if="form.errors.name" :class="fieldError">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label :class="fieldLabel">{{ t('reportBuilder.fields.description') }}</label>
                        <textarea
                            v-model="form.description"
                            rows="2"
                            maxlength="1000"
                            :class="fieldArea"
                            :placeholder="t('reportBuilder.placeholders.description')"
                        ></textarea>
                    </div>
                </div>
            </Card>

            <!-- Data Source -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('reportBuilder.sections.dataSource') }}</h3></div>
                <div class="p-5">
                    <select
                        v-model="form.data_source"
                        :class="[fieldInput, 'md:w-80']"
                    >
                        <option value="" disabled>{{ t('reportBuilder.placeholders.dataSource') }}</option>
                        <option v-for="(source, key) in dataSources" :key="key" :value="key">{{ source.label }}</option>
                    </select>
                    <p v-if="form.errors.data_source" :class="fieldError">{{ form.errors.data_source }}</p>
                </div>
            </Card>

            <!-- Columns -->
            <Card v-if="form.data_source" :padded="false">
                <div class="flex items-center justify-between px-5 pt-5">
                    <h3 class="text-sm font-semibold text-text-primary">{{ t('reportBuilder.sections.columns') }}</h3>
                    <div class="flex items-center gap-2">
                        <Button type="button" variant="link" size="xs" @click="selectAllColumns">{{ t('reportBuilder.selectAll') }}</Button>
                        <span class="text-text-tertiary">|</span>
                        <Button type="button" variant="link" size="xs" @click="deselectAllColumns">{{ t('reportBuilder.deselectAll') }}</Button>
                    </div>
                </div>
                <div class="p-5">
                    <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
                        <label
                            v-for="col in availableColumns"
                            :key="col.key"
                            class="flex cursor-pointer items-center gap-2 rounded-md p-2 transition-colors hover:bg-surface-overlay"
                        >
                            <input
                                type="checkbox"
                                :checked="isColumnSelected(col.key)"
                                @change="toggleColumn(col.key)"
                                class="h-4 w-4 rounded border-border-subtle text-brand ds-focus-ring"
                            />
                            <span class="text-sm text-text-secondary">{{ col.label }}</span>
                            <span class="text-xs text-text-tertiary">({{ col.type }})</span>
                        </label>
                    </div>

                    <!-- Selected columns order -->
                    <div v-if="form.columns.length > 0" class="mt-4 border-t border-border-subtle pt-4">
                        <p class="mb-2 text-sm font-medium text-text-secondary">{{ t('reportBuilder.columnOrder') }}</p>
                        <div class="space-y-2">
                            <div
                                v-for="(colKey, index) in form.columns"
                                :key="colKey"
                                class="flex items-center gap-2 rounded-lg border border-border-subtle bg-surface-canvas p-4"
                            >
                                <span class="w-6 text-xs text-text-tertiary">{{ index + 1 }}.</span>
                                <Badge variant="neutral" size="sm" class="flex-1 justify-start">{{ columnLabel(colKey) }}</Badge>
                                <button
                                    type="button"
                                    @click="moveColumn(index, -1)"
                                    :disabled="index === 0"
                                    class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-text-primary disabled:opacity-30"
                                >
                                    <ChevronUp :size="16" />
                                </button>
                                <button
                                    type="button"
                                    @click="moveColumn(index, 1)"
                                    :disabled="index === form.columns.length - 1"
                                    class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-text-primary disabled:opacity-30"
                                >
                                    <ChevronDown :size="16" />
                                </button>
                            </div>
                        </div>
                    </div>
                    <p v-if="form.errors.columns" :class="fieldError">{{ form.errors.columns }}</p>
                </div>
            </Card>

            <!-- Filters -->
            <Card v-if="form.data_source" :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('reportBuilder.sections.filters') }}</h3></div>
                <div class="p-5">
                    <div class="space-y-3">
                        <div
                            v-for="(filter, index) in form.filters"
                            :key="index"
                            class="flex flex-wrap items-center gap-3 rounded-lg border border-border-subtle bg-surface-canvas p-4"
                        >
                            <select
                                v-model="filter.field"
                                :class="[fieldInput, 'min-w-[150px] flex-1']"
                            >
                                <option value="" disabled>{{ t('reportBuilder.placeholders.filterField') }}</option>
                                <option v-for="col in availableColumns" :key="col.key" :value="col.key">{{ col.label }}</option>
                            </select>
                            <select
                                v-model="filter.operator"
                                :class="[fieldInput, 'w-40']"
                            >
                                <option v-for="op in operators" :key="op.value" :value="op.value">{{ op.label }}</option>
                            </select>
                            <input
                                v-if="!noValueOperators.includes(filter.operator)"
                                v-model="filter.value"
                                :type="getColumnType(filter.field) === 'number' || getColumnType(filter.field) === 'currency' ? 'number' : getColumnType(filter.field) === 'date' ? 'date' : 'text'"
                                :class="[fieldInput, 'min-w-[150px] flex-1']"
                                :placeholder="t('reportBuilder.placeholders.filterValue')"
                            />
                            <button
                                type="button"
                                @click="removeFilter(index)"
                                class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-sunken hover:text-status-danger"
                            >
                                <Trash2 :size="16" />
                            </button>
                        </div>
                    </div>
                    <Button type="button" variant="secondary" size="sm" class="mt-3" @click="addFilter">
                        <Plus :size="14" />
                        {{ t('reportBuilder.addFilter') }}
                    </Button>
                </div>
            </Card>

            <!-- Sort -->
            <Card v-if="form.data_source" :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('reportBuilder.sections.sort') }}</h3></div>
                <div class="p-5">
                    <div class="flex flex-wrap gap-3">
                        <select
                            v-model="form.sort.field"
                            :class="[fieldInput, 'min-w-[200px] flex-1']"
                        >
                            <option value="">{{ t('reportBuilder.placeholders.sortField') }}</option>
                            <option v-for="col in availableColumns" :key="col.key" :value="col.key">{{ col.label }}</option>
                        </select>
                        <select
                            v-model="form.sort.direction"
                            :class="[fieldInput, 'w-40']"
                        >
                            <option value="asc">{{ t('reportBuilder.sortAsc') }}</option>
                            <option value="desc">{{ t('reportBuilder.sortDesc') }}</option>
                        </select>
                    </div>
                </div>
            </Card>

            <!-- Chart Settings -->
            <Card v-if="form.data_source && form.columns.length > 0" :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('reportBuilder.sections.chart') }}</h3></div>
                <div class="p-5">
                    <div class="flex flex-wrap gap-4">
                        <div class="min-w-[200px] flex-1">
                            <label :class="fieldLabel">{{ t('reportBuilder.fields.chartType') }}</label>
                            <select
                                v-model="form.chart_type"
                                :class="fieldInput"
                            >
                                <option v-for="ct in chartTypes" :key="ct.value" :value="ct.value">{{ ct.label }}</option>
                            </select>
                        </div>
                        <div v-if="form.chart_type" class="min-w-[200px] flex-1">
                            <label :class="fieldLabel">{{ t('reportBuilder.fields.chartField') }}</label>
                            <select
                                v-model="form.chart_field"
                                :class="fieldInput"
                            >
                                <option value="">{{ t('reportBuilder.placeholders.chartField') }}</option>
                                <option v-for="col in availableColumns" :key="col.key" :value="col.key">{{ col.label }}</option>
                            </select>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Preview -->
            <Card v-if="form.data_source && form.columns.length > 0" :padded="false">
                <div class="flex items-center justify-between px-5 pt-5">
                    <h3 class="text-sm font-semibold text-text-primary">{{ t('reportBuilder.sections.preview') }}</h3>
                    <Button type="button" variant="secondary" size="sm" :loading="previewLoading" :disabled="previewLoading" @click="previewReport">
                        <RefreshCw v-if="!previewLoading" :size="14" />
                        {{ previewLoading ? t('reportBuilder.previewing') : t('reportBuilder.previewReport') }}
                    </Button>
                </div>
                <div class="p-5">
                    <p v-if="previewError" class="mb-4 text-sm text-status-danger">{{ previewError }}</p>

                    <div v-if="previewData" class="overflow-x-auto">
                        <p class="mb-3 text-sm text-text-secondary">
                            {{ t('reportBuilder.previewShowing', { count: previewData.data?.length || 0, total: previewData.total || 0 }) }}
                        </p>
                        <table class="min-w-full divide-y divide-border-subtle">
                            <thead class="bg-surface-overlay">
                                <tr>
                                    <th
                                        v-for="col in form.columns"
                                        :key="col"
                                        class="px-4 py-2 text-left text-[11px] font-medium uppercase tracking-wider text-text-tertiary"
                                    >
                                        {{ previewData.columnLabels?.[col] || columnLabel(col) }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-subtle">
                                <tr v-for="(row, idx) in previewData.data" :key="idx" class="transition-colors hover:bg-surface-overlay">
                                    <td
                                        v-for="col in form.columns"
                                        :key="col"
                                        class="whitespace-nowrap px-4 py-2 text-sm text-text-secondary"
                                    >
                                        {{ row[col] ?? '-' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-else-if="!previewLoading && !previewError" class="py-8 text-center">
                        <p class="text-sm text-text-tertiary">{{ t('reportBuilder.previewHint') }}</p>
                    </div>
                </div>
            </Card>

            <!-- Save Actions -->
            <Card :padded="false">
                <div class="flex items-center justify-between p-5">
                    <label class="flex cursor-pointer items-center gap-2">
                        <input
                            v-model="form.is_shared"
                            type="checkbox"
                            class="h-4 w-4 rounded border-border-subtle text-brand ds-focus-ring"
                        />
                        <span class="text-sm text-text-secondary">{{ t('reportBuilder.shareWithTeam') }}</span>
                    </label>
                    <div class="flex gap-3">
                        <Button variant="secondary" as="Link" :href="route('reports.builder.index')">{{ t('reportBuilder.cancel') }}</Button>
                        <Button
                            type="button"
                            variant="default"
                            :loading="form.processing"
                            :disabled="form.processing || !form.name || !form.data_source || form.columns.length === 0"
                            @click="saveReport"
                        >
                            {{ form.processing ? t('reportBuilder.saving') : t('reportBuilder.saveReport') }}
                        </Button>
                    </div>
                </div>
            </Card>

        </div>
    </AppLayout>
</template>
