<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    adjustment: Object,
});

const getTypeBadgeClass = (type) => {
    const classes = {
        'manual': 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300',
        'recount': 'bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300',
        'damage': 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300',
        'loss': 'bg-orange-100 dark:bg-orange-900/30 text-orange-800 dark:text-orange-300',
        'return': 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
        'correction': 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300',
        'order': 'bg-gray-100 dark:bg-gray-900/30 text-gray-800 dark:text-gray-300',
    };
    return classes[type] || classes.manual;
};

const typeLabels = {
    'manual': 'Manual Adjustment',
    'recount': 'Stock Recount',
    'damage': 'Damage',
    'loss': 'Loss',
    'return': 'Return',
    'correction': 'Correction',
    'order': 'Order',
};

const isIncrease = computed(() => props.adjustment.adjustment_quantity > 0);
const isDecrease = computed(() => props.adjustment.adjustment_quantity < 0);
</script>

<template>
    <Head title="Stock Adjustment Details" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">Stock Adjustment Details</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Adjustment #{{ adjustment.id }}</p>
                </div>
                <Link
                    :href="route('stock-adjustments.index')"
                    class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                >
                    Back to List
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <!-- Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <!-- Before -->
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm rounded-lg p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Stock Before</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ adjustment.quantity_before }}</p>
                    </div>

                    <!-- Change -->
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm rounded-lg p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Adjustment</p>
                        <p class="text-3xl font-bold" :class="{
                            'text-green-600 dark:text-green-400': isIncrease,
                            'text-red-600 dark:text-red-400': isDecrease,
                            'text-gray-600 dark:text-gray-400': !isIncrease && !isDecrease
                        }">
                            {{ adjustment.adjustment_quantity > 0 ? '+' : '' }}{{ adjustment.adjustment_quantity }}
                        </p>
                    </div>

                    <!-- After -->
                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm rounded-lg p-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Stock After</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ adjustment.quantity_after }}</p>
                    </div>
                </div>

                <!-- Adjustment Details -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm rounded-lg p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-6">Adjustment Information</h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Product</label>
                            <Link
                                :href="route('products.show', adjustment.product.id)"
                                class="text-gray-900 dark:text-gray-100 hover:text-primary-400 font-medium"
                            >
                                {{ adjustment.product.name }}
                            </Link>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">SKU: {{ adjustment.product.sku }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Type</label>
                            <span class="inline-flex px-3 py-1 text-sm font-semibold rounded-full" :class="getTypeBadgeClass(adjustment.type)">
                                {{ typeLabels[adjustment.type] || adjustment.type }}
                            </span>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Adjusted By</label>
                            <p class="text-gray-900 dark:text-gray-100">{{ adjustment.user?.name || 'System' }}</p>
                            <p v-if="adjustment.user?.email" class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                {{ adjustment.user.email }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Date & Time</label>
                            <p class="text-gray-900 dark:text-gray-100">
                                {{ new Date(adjustment.created_at).toLocaleDateString('en-US', {
                                    year: 'numeric',
                                    month: 'long',
                                    day: 'numeric'
                                }) }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                {{ new Date(adjustment.created_at).toLocaleTimeString('en-US', {
                                    hour: '2-digit',
                                    minute: '2-digit'
                                }) }}
                            </p>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Reason</label>
                            <p class="text-gray-900 dark:text-gray-100">{{ adjustment.reason }}</p>
                        </div>

                        <div v-if="adjustment.notes" class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Notes</label>
                            <p class="text-gray-900 dark:text-gray-100 whitespace-pre-line">{{ adjustment.notes }}</p>
                        </div>

                        <div v-if="adjustment.reference_type" class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Reference</label>
                            <div class="p-3 bg-gray-50 dark:bg-dark-bg rounded-lg border border-gray-200 dark:border-dark-border">
                                <p class="text-sm text-gray-900 dark:text-gray-100">
                                    {{ adjustment.reference_type }} #{{ adjustment.reference_id }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Visual Timeline -->
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-6">Stock Change Timeline</h3>

                    <div class="relative">
                        <!-- Timeline line -->
                        <div class="absolute left-8 top-8 bottom-8 w-0.5 bg-gray-200 dark:bg-dark-border"></div>

                        <!-- Before -->
                        <div class="relative flex items-start gap-4 mb-8">
                            <div class="flex-shrink-0 w-16 h-16 bg-blue-100 dark:bg-blue-900/30 rounded-full flex items-center justify-center relative z-10">
                                <svg class="w-8 h-8 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div class="flex-1 bg-gray-50 dark:bg-dark-bg rounded-lg p-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Starting Stock</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-1">{{ adjustment.quantity_before }} units</p>
                            </div>
                        </div>

                        <!-- Adjustment -->
                        <div class="relative flex items-start gap-4 mb-8">
                            <div class="flex-shrink-0 w-16 h-16 rounded-full flex items-center justify-center relative z-10" :class="{
                                'bg-green-100 dark:bg-green-900/30': isIncrease,
                                'bg-red-100 dark:bg-red-900/30': isDecrease,
                            }">
                                <svg v-if="isIncrease" class="w-8 h-8 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11l5-5m0 0l5 5m-5-5v12" />
                                </svg>
                                <svg v-else class="w-8 h-8 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 13l-5 5m0 0l-5-5m5 5V6" />
                                </svg>
                            </div>
                            <div class="flex-1 rounded-lg p-4" :class="{
                                'bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800': isIncrease,
                                'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800': isDecrease,
                            }">
                                <p class="text-sm font-medium" :class="{
                                    'text-green-600 dark:text-green-400': isIncrease,
                                    'text-red-600 dark:text-red-400': isDecrease,
                                }">
                                    {{ isIncrease ? 'Stock Increased' : 'Stock Decreased' }}
                                </p>
                                <p class="text-2xl font-bold mt-1" :class="{
                                    'text-green-900 dark:text-green-100': isIncrease,
                                    'text-red-900 dark:text-red-100': isDecrease,
                                }">
                                    {{ adjustment.adjustment_quantity > 0 ? '+' : '' }}{{ adjustment.adjustment_quantity }} units
                                </p>
                                <p class="text-xs mt-2" :class="{
                                    'text-green-700 dark:text-green-300': isIncrease,
                                    'text-red-700 dark:text-red-300': isDecrease,
                                }">
                                    {{ adjustment.reason }}
                                </p>
                            </div>
                        </div>

                        <!-- After -->
                        <div class="relative flex items-start gap-4">
                            <div class="flex-shrink-0 w-16 h-16 bg-primary-400/20 rounded-full flex items-center justify-center relative z-10">
                                <svg class="w-8 h-8 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div class="flex-1 bg-primary-400/10 border border-primary-400/30 rounded-lg p-4">
                                <p class="text-sm font-medium text-primary-600 dark:text-primary-400">Final Stock</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-1">{{ adjustment.quantity_after }} units</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
