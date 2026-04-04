<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
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
</script>

<template>
    <Head :title="t('reportBuilder.createReport')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">{{ t('reportBuilder.createReport') }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ t('reportBuilder.createSubtitle') }}</p>
                </div>
                <Link
                    :href="route('reports.builder.index')"
                    class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                >
                    {{ t('reportBuilder.backToList') }}
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

                <!-- Report Info -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ t('reportBuilder.sections.info') }}</h3>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('reportBuilder.fields.name') }} <span class="text-red-500">*</span></label>
                            <input
                                v-model="form.name"
                                type="text"
                                maxlength="255"
                                class="w-full px-4 py-2 bg-white dark:bg-dark-bg border border-gray-300 dark:border-dark-border rounded-lg text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition"
                                :placeholder="t('reportBuilder.placeholders.name')"
                            />
                            <p v-if="form.errors.name" class="text-sm text-red-500 mt-1">{{ form.errors.name }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('reportBuilder.fields.description') }}</label>
                            <textarea
                                v-model="form.description"
                                rows="2"
                                maxlength="1000"
                                class="w-full px-4 py-2 bg-white dark:bg-dark-bg border border-gray-300 dark:border-dark-border rounded-lg text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition"
                                :placeholder="t('reportBuilder.placeholders.description')"
                            ></textarea>
                        </div>
                    </div>
                </div>

                <!-- Data Source -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ t('reportBuilder.sections.dataSource') }}</h3>
                    <select
                        v-model="form.data_source"
                        class="w-full md:w-80 px-4 py-2 bg-white dark:bg-dark-bg border border-gray-300 dark:border-dark-border rounded-lg text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 transition"
                    >
                        <option value="" disabled>{{ t('reportBuilder.placeholders.dataSource') }}</option>
                        <option v-for="(source, key) in dataSources" :key="key" :value="key">{{ source.label }}</option>
                    </select>
                    <p v-if="form.errors.data_source" class="text-sm text-red-500 mt-1">{{ form.errors.data_source }}</p>
                </div>

                <!-- Columns -->
                <div v-if="form.data_source" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ t('reportBuilder.sections.columns') }}</h3>
                        <div class="flex gap-2">
                            <button @click="selectAllColumns" class="text-xs text-primary-400 hover:text-primary-300 transition">{{ t('reportBuilder.selectAll') }}</button>
                            <span class="text-gray-400">|</span>
                            <button @click="deselectAllColumns" class="text-xs text-primary-400 hover:text-primary-300 transition">{{ t('reportBuilder.deselectAll') }}</button>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                        <label
                            v-for="col in availableColumns"
                            :key="col.key"
                            class="flex items-center gap-2 p-2 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-dark-bg/50 transition"
                        >
                            <input
                                type="checkbox"
                                :checked="isColumnSelected(col.key)"
                                @change="toggleColumn(col.key)"
                                class="w-4 h-4 rounded border-gray-300 dark:border-dark-border text-primary-600 focus:ring-primary-500"
                            />
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ col.label }}</span>
                            <span class="text-xs text-gray-400 dark:text-gray-500">({{ col.type }})</span>
                        </label>
                    </div>

                    <!-- Selected columns order -->
                    <div v-if="form.columns.length > 0" class="mt-4 pt-4 border-t border-gray-200 dark:border-dark-border">
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ t('reportBuilder.columnOrder') }}</p>
                        <div class="space-y-1">
                            <div
                                v-for="(colKey, index) in form.columns"
                                :key="colKey"
                                class="flex items-center gap-2 px-3 py-1.5 bg-gray-50 dark:bg-dark-bg rounded-lg"
                            >
                                <span class="text-xs text-gray-400 w-6">{{ index + 1 }}.</span>
                                <span class="text-sm text-gray-700 dark:text-gray-300 flex-1">{{ columnLabel(colKey) }}</span>
                                <button
                                    @click="moveColumn(index, -1)"
                                    :disabled="index === 0"
                                    class="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 disabled:opacity-30 transition"
                                >
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" /></svg>
                                </button>
                                <button
                                    @click="moveColumn(index, 1)"
                                    :disabled="index === form.columns.length - 1"
                                    class="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 disabled:opacity-30 transition"
                                >
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                    <p v-if="form.errors.columns" class="text-sm text-red-500 mt-2">{{ form.errors.columns }}</p>
                </div>

                <!-- Filters -->
                <div v-if="form.data_source" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ t('reportBuilder.sections.filters') }}</h3>
                    <div class="space-y-3">
                        <div
                            v-for="(filter, index) in form.filters"
                            :key="index"
                            class="flex flex-wrap items-center gap-3 p-3 bg-gray-50 dark:bg-dark-bg rounded-lg"
                        >
                            <select
                                v-model="filter.field"
                                class="flex-1 min-w-[150px] px-3 py-2 bg-white dark:bg-dark-card border border-gray-300 dark:border-dark-border rounded-lg text-sm text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-primary-500 transition"
                            >
                                <option value="" disabled>{{ t('reportBuilder.placeholders.filterField') }}</option>
                                <option v-for="col in availableColumns" :key="col.key" :value="col.key">{{ col.label }}</option>
                            </select>
                            <select
                                v-model="filter.operator"
                                class="w-40 px-3 py-2 bg-white dark:bg-dark-card border border-gray-300 dark:border-dark-border rounded-lg text-sm text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-primary-500 transition"
                            >
                                <option v-for="op in operators" :key="op.value" :value="op.value">{{ op.label }}</option>
                            </select>
                            <input
                                v-if="!noValueOperators.includes(filter.operator)"
                                v-model="filter.value"
                                :type="getColumnType(filter.field) === 'number' || getColumnType(filter.field) === 'currency' ? 'number' : getColumnType(filter.field) === 'date' ? 'date' : 'text'"
                                class="flex-1 min-w-[150px] px-3 py-2 bg-white dark:bg-dark-card border border-gray-300 dark:border-dark-border rounded-lg text-sm text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-primary-500 transition"
                                :placeholder="t('reportBuilder.placeholders.filterValue')"
                            />
                            <button
                                @click="removeFilter(index)"
                                class="p-2 text-gray-400 hover:text-red-400 transition"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    <button
                        @click="addFilter"
                        class="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 text-sm text-primary-400 hover:text-primary-300 transition"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                        {{ t('reportBuilder.addFilter') }}
                    </button>
                </div>

                <!-- Sort -->
                <div v-if="form.data_source" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ t('reportBuilder.sections.sort') }}</h3>
                    <div class="flex flex-wrap gap-3">
                        <select
                            v-model="form.sort.field"
                            class="flex-1 min-w-[200px] px-4 py-2 bg-white dark:bg-dark-bg border border-gray-300 dark:border-dark-border rounded-lg text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-primary-500 transition"
                        >
                            <option value="">{{ t('reportBuilder.placeholders.sortField') }}</option>
                            <option v-for="col in availableColumns" :key="col.key" :value="col.key">{{ col.label }}</option>
                        </select>
                        <select
                            v-model="form.sort.direction"
                            class="w-40 px-4 py-2 bg-white dark:bg-dark-bg border border-gray-300 dark:border-dark-border rounded-lg text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-primary-500 transition"
                        >
                            <option value="asc">{{ t('reportBuilder.sortAsc') }}</option>
                            <option value="desc">{{ t('reportBuilder.sortDesc') }}</option>
                        </select>
                    </div>
                </div>

                <!-- Chart Settings -->
                <div v-if="form.data_source && form.columns.length > 0" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ t('reportBuilder.sections.chart') }}</h3>
                    <div class="flex flex-wrap gap-4">
                        <div class="flex-1 min-w-[200px]">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('reportBuilder.fields.chartType') }}</label>
                            <select
                                v-model="form.chart_type"
                                class="w-full px-4 py-2 bg-white dark:bg-dark-bg border border-gray-300 dark:border-dark-border rounded-lg text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-primary-500 transition"
                            >
                                <option v-for="ct in chartTypes" :key="ct.value" :value="ct.value">{{ ct.label }}</option>
                            </select>
                        </div>
                        <div v-if="form.chart_type" class="flex-1 min-w-[200px]">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('reportBuilder.fields.chartField') }}</label>
                            <select
                                v-model="form.chart_field"
                                class="w-full px-4 py-2 bg-white dark:bg-dark-bg border border-gray-300 dark:border-dark-border rounded-lg text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-primary-500 transition"
                            >
                                <option value="">{{ t('reportBuilder.placeholders.chartField') }}</option>
                                <option v-for="col in availableColumns" :key="col.key" :value="col.key">{{ col.label }}</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Preview -->
                <div v-if="form.data_source && form.columns.length > 0" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ t('reportBuilder.sections.preview') }}</h3>
                        <button
                            @click="previewReport"
                            :disabled="previewLoading"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white font-medium rounded-lg transition flex items-center gap-2"
                        >
                            <svg v-if="previewLoading" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            {{ previewLoading ? t('reportBuilder.previewing') : t('reportBuilder.previewReport') }}
                        </button>
                    </div>

                    <p v-if="previewError" class="text-sm text-red-500 mb-4">{{ previewError }}</p>

                    <div v-if="previewData" class="overflow-x-auto">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">
                            {{ t('reportBuilder.previewShowing', { count: previewData.data?.length || 0, total: previewData.total || 0 }) }}
                        </p>
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                            <thead class="bg-gray-50 dark:bg-dark-bg">
                                <tr>
                                    <th
                                        v-for="col in form.columns"
                                        :key="col"
                                        class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider"
                                    >
                                        {{ previewData.columnLabels?.[col] || columnLabel(col) }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-dark-border">
                                <tr v-for="(row, idx) in previewData.data" :key="idx" class="hover:bg-gray-50 dark:hover:bg-dark-bg/50">
                                    <td
                                        v-for="col in form.columns"
                                        :key="col"
                                        class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap"
                                    >
                                        {{ row[col] ?? '-' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-else-if="!previewLoading && !previewError" class="text-center py-8">
                        <p class="text-sm text-gray-400 dark:text-gray-500">{{ t('reportBuilder.previewHint') }}</p>
                    </div>
                </div>

                <!-- Save Actions -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-sm p-6">
                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input
                                v-model="form.is_shared"
                                type="checkbox"
                                class="w-4 h-4 rounded border-gray-300 dark:border-dark-border text-primary-600 focus:ring-primary-500"
                            />
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ t('reportBuilder.shareWithTeam') }}</span>
                        </label>
                        <div class="flex gap-3">
                            <Link
                                :href="route('reports.builder.index')"
                                class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                            >
                                {{ t('reportBuilder.cancel') }}
                            </Link>
                            <button
                                @click="saveReport"
                                :disabled="form.processing || !form.name || !form.data_source || form.columns.length === 0"
                                class="px-6 py-2 bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white font-medium rounded-lg transition"
                            >
                                {{ form.processing ? t('reportBuilder.saving') : t('reportBuilder.saveReport') }}
                            </button>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
