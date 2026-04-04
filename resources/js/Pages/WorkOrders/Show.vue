<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    workOrder: Object,
});

const processing = ref(false);
const quantityProduced = ref(props.workOrder.quantity);

const statusSteps = [
    { key: 'draft', label: 'Draft' },
    { key: 'pending', label: 'Pending' },
    { key: 'in_progress', label: 'In Progress' },
    { key: 'completed', label: 'Completed' },
];

const statusOrder = { draft: 0, pending: 1, in_progress: 2, completed: 3, cancelled: -1 };

const isStepCompleted = (stepKey) => {
    if (props.workOrder.status === 'cancelled') return false;
    return statusOrder[stepKey] <= statusOrder[props.workOrder.status];
};

const isStepCurrent = (stepKey) => {
    return stepKey === props.workOrder.status;
};

const getStatusBadgeClass = (status) => {
    const classes = {
        'draft': 'bg-gray-100 dark:bg-gray-900/30 text-gray-800 dark:text-gray-300',
        'pending': 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300',
        'in_progress': 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300',
        'completed': 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
        'cancelled': 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300',
    };
    return classes[status] || classes.draft;
};

const getStatusLabel = (status) => {
    const labels = {
        'draft': 'Draft',
        'pending': 'Pending',
        'in_progress': 'In Progress',
        'completed': 'Completed',
        'cancelled': 'Cancelled',
    };
    return labels[status] || status;
};

const formatDate = (dateStr) => {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const canStart = ['draft', 'pending'].includes(props.workOrder.status);
const canComplete = props.workOrder.status === 'in_progress';
const canCancel = ['draft', 'pending', 'in_progress'].includes(props.workOrder.status);
const isReadOnly = ['completed', 'cancelled'].includes(props.workOrder.status);

const startProduction = () => {
    if (!confirm('Start production for this work order? Components will be reserved.')) return;
    processing.value = true;
    router.post(route('work-orders.start', props.workOrder.id), {}, {
        onFinish: () => { processing.value = false; },
    });
};

const completeProduction = () => {
    if (!confirm(`Complete this work order? ${quantityProduced.value} units will be added to assembly stock.`)) return;
    processing.value = true;
    router.post(route('work-orders.complete', props.workOrder.id), {
        quantity_produced: quantityProduced.value,
    }, {
        onFinish: () => { processing.value = false; },
    });
};

const cancelWorkOrder = () => {
    if (!confirm('Cancel this work order? Any reserved components will be released.')) return;
    processing.value = true;
    router.post(route('work-orders.cancel', props.workOrder.id), {}, {
        onFinish: () => { processing.value = false; },
    });
};
</script>

<template>
    <Head :title="`Work Order ${workOrder.wo_number}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">{{ workOrder.wo_number }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Work order details</p>
                </div>
                <div class="flex gap-2">
                    <Link
                        :href="route('work-orders.index')"
                        class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                    >
                        Back to Work Orders
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

                <!-- Status Timeline -->
                <div v-if="workOrder.status !== 'cancelled'" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-4">Production Progress</h3>
                    <div class="flex items-center justify-between">
                        <template v-for="(step, index) in statusSteps" :key="step.key">
                            <div class="flex items-center">
                                <div
                                    class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold transition"
                                    :class="isStepCompleted(step.key)
                                        ? 'bg-primary-500 text-white'
                                        : 'bg-gray-200 dark:bg-dark-bg text-gray-500 dark:text-gray-400'"
                                >
                                    <svg v-if="isStepCompleted(step.key) && !isStepCurrent(step.key)" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                    <span v-else>{{ index + 1 }}</span>
                                </div>
                                <span
                                    class="ml-2 text-sm font-medium"
                                    :class="isStepCurrent(step.key) ? 'text-primary-400' : isStepCompleted(step.key) ? 'text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400'"
                                >
                                    {{ step.label }}
                                </span>
                            </div>
                            <div
                                v-if="index < statusSteps.length - 1"
                                class="flex-1 h-0.5 mx-4"
                                :class="isStepCompleted(statusSteps[index + 1].key) ? 'bg-primary-500' : 'bg-gray-200 dark:bg-dark-bg'"
                            ></div>
                        </template>
                    </div>
                </div>

                <!-- Cancelled Banner -->
                <div v-if="workOrder.status === 'cancelled'" class="bg-red-900/20 border border-red-800 rounded-lg p-4">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 text-red-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                        </svg>
                        <span class="text-red-300 font-medium">This work order has been cancelled.</span>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Main Content -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Work Order Details -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Work Order Details</h3>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Assembly Product</p>
                                    <Link
                                        v-if="workOrder.product"
                                        :href="route('products.show', workOrder.product.id)"
                                        class="text-primary-400 hover:text-primary-300 font-medium"
                                    >
                                        {{ workOrder.product.name }}
                                    </Link>
                                    <p v-if="workOrder.product" class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">SKU: {{ workOrder.product.sku }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Status</p>
                                    <span :class="['px-2 py-1 text-xs font-semibold rounded-full', getStatusBadgeClass(workOrder.status)]">
                                        {{ getStatusLabel(workOrder.status) }}
                                    </span>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Quantity to Produce</p>
                                    <p class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ workOrder.quantity }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Quantity Produced</p>
                                    <p class="text-lg font-bold" :class="(workOrder.quantity_produced || 0) >= workOrder.quantity ? 'text-green-400' : 'text-gray-900 dark:text-gray-100'">
                                        {{ workOrder.quantity_produced || 0 }}
                                    </p>
                                </div>
                                <div v-if="workOrder.warehouse">
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Warehouse</p>
                                    <p class="text-sm text-gray-900 dark:text-gray-100">{{ workOrder.warehouse.name }}</p>
                                </div>
                                <div v-if="workOrder.notes">
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Notes</p>
                                    <p class="text-sm text-gray-900 dark:text-gray-100">{{ workOrder.notes }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Components Table -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg overflow-hidden">
                            <div class="p-6 border-b border-gray-200 dark:border-dark-border">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Required Components</h3>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                                    <thead class="bg-gray-50 dark:bg-dark-bg">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Component</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">SKU</th>
                                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Required Qty</th>
                                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Consumed</th>
                                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Available</th>
                                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-dark-card divide-y divide-gray-200 dark:divide-dark-border">
                                        <tr v-for="comp in (workOrder.components || [])" :key="comp.id" class="hover:bg-gray-50 dark:hover:bg-dark-bg/50 transition">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <Link
                                                    v-if="comp.component"
                                                    :href="route('products.show', comp.component.id)"
                                                    class="text-primary-400 hover:text-primary-300 font-medium text-sm"
                                                >
                                                    {{ comp.component.name }}
                                                </Link>
                                                <span v-else class="text-sm text-gray-500">Unknown</span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                {{ comp.component?.sku || '-' }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium text-gray-900 dark:text-gray-100">
                                                {{ comp.quantity_required }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900 dark:text-gray-100">
                                                {{ comp.quantity_consumed || 0 }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm" :class="(comp.component?.stock || 0) >= comp.quantity_required ? 'text-green-400' : 'text-red-400'">
                                                {{ comp.component?.stock || 0 }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                                <span
                                                    v-if="(comp.quantity_consumed || 0) >= comp.quantity_required"
                                                    class="px-2 py-0.5 text-xs font-semibold rounded-full bg-green-900/30 text-green-300"
                                                >
                                                    Consumed
                                                </span>
                                                <span
                                                    v-else-if="(comp.component?.stock || 0) >= comp.quantity_required"
                                                    class="px-2 py-0.5 text-xs font-semibold rounded-full bg-yellow-900/30 text-yellow-300"
                                                >
                                                    Ready
                                                </span>
                                                <span
                                                    v-else
                                                    class="px-2 py-0.5 text-xs font-semibold rounded-full bg-red-900/30 text-red-300"
                                                >
                                                    Insufficient
                                                </span>
                                            </td>
                                        </tr>

                                        <tr v-if="!workOrder.components || workOrder.components.length === 0">
                                            <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                                No components recorded for this work order.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar -->
                    <div class="space-y-6">
                        <!-- Actions -->
                        <div v-if="!isReadOnly" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Actions</h3>
                            <div class="space-y-3">
                                <button
                                    v-if="canStart"
                                    @click="startProduction"
                                    :disabled="processing"
                                    class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition disabled:opacity-50"
                                >
                                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    Start Production
                                </button>

                                <div v-if="canComplete" class="space-y-2">
                                    <label class="block text-sm font-medium text-gray-600 dark:text-gray-300">
                                        Quantity Produced
                                    </label>
                                    <input
                                        v-model.number="quantityProduced"
                                        type="number"
                                        min="1"
                                        :max="workOrder.quantity"
                                        class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    />
                                    <button
                                        @click="completeProduction"
                                        :disabled="processing"
                                        class="w-full px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition disabled:opacity-50"
                                    >
                                        <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        Complete Production
                                    </button>
                                </div>

                                <button
                                    v-if="canCancel"
                                    @click="cancelWorkOrder"
                                    :disabled="processing"
                                    class="w-full px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition disabled:opacity-50"
                                >
                                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                    Cancel Work Order
                                </button>
                            </div>
                        </div>

                        <!-- Dates / Info -->
                        <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Information</h3>
                            <div class="space-y-3">
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Created By</p>
                                    <p class="text-sm text-gray-900 dark:text-gray-100">{{ workOrder.created_by?.name || '-' }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Created</p>
                                    <p class="text-sm text-gray-900 dark:text-gray-100">{{ formatDate(workOrder.created_at) }}</p>
                                </div>
                                <div v-if="workOrder.started_at">
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Production Started</p>
                                    <p class="text-sm text-gray-900 dark:text-gray-100">{{ formatDate(workOrder.started_at) }}</p>
                                </div>
                                <div v-if="workOrder.completed_at">
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Completed</p>
                                    <p class="text-sm text-gray-900 dark:text-gray-100">{{ formatDate(workOrder.completed_at) }}</p>
                                </div>
                                <div v-if="workOrder.cancelled_at">
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Cancelled</p>
                                    <p class="text-sm text-gray-900 dark:text-gray-100">{{ formatDate(workOrder.cancelled_at) }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Last Updated</p>
                                    <p class="text-sm text-gray-900 dark:text-gray-100">{{ formatDate(workOrder.updated_at) }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
