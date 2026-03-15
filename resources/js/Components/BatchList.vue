<script setup>
import { ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    productId: Number,
    batches: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['batch-created']);

const localBatches = ref([...props.batches]);
const showForm = ref(false);
const loading = ref(false);
const error = ref(null);

const form = ref({
    batch_number: '',
    quantity: '',
    manufactured_date: '',
    expiry_date: '',
    notes: '',
});

const resetForm = () => {
    form.value = {
        batch_number: '',
        quantity: '',
        manufactured_date: '',
        expiry_date: '',
        notes: '',
    };
    error.value = null;
};

const createBatch = async () => {
    loading.value = true;
    error.value = null;

    try {
        const payload = {
            quantity: parseInt(form.value.quantity) || 0,
        };
        if (form.value.batch_number) payload.batch_number = form.value.batch_number;
        if (form.value.manufactured_date) payload.manufactured_date = form.value.manufactured_date;
        if (form.value.expiry_date) payload.expiry_date = form.value.expiry_date;
        if (form.value.notes) payload.notes = form.value.notes;

        const response = await axios.post(`/api/v1/products/${props.productId}/batches`, payload);
        localBatches.value.unshift(response.data.data);
        emit('batch-created', response.data.data);
        resetForm();
        showForm.value = false;
    } catch (err) {
        if (err.response?.data?.errors) {
            error.value = Object.values(err.response.data.errors).flat().join(', ');
        } else {
            error.value = err.response?.data?.message || 'Failed to create batch';
        }
    } finally {
        loading.value = false;
    }
};

const isExpired = (expiryDate) => {
    if (!expiryDate) return false;
    return new Date(expiryDate) < new Date();
};
</script>

<template>
    <div>
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                Batch Tracking
            </h3>
            <button
                @click="showForm = !showForm"
                class="inline-flex items-center px-3 py-1.5 bg-primary-400 text-white text-sm font-medium rounded-md hover:bg-primary-500 transition"
            >
                <svg v-if="!showForm" class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                {{ showForm ? 'Cancel' : 'Add Batch' }}
            </button>
        </div>

        <!-- Add Batch Form -->
        <div v-if="showForm" class="mb-4 p-4 bg-gray-50 dark:bg-dark-bg/50 rounded-lg border border-gray-200 dark:border-dark-border">
            <div v-if="error" class="mb-3 p-2 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 text-sm rounded">
                {{ error }}
            </div>
            <div class="grid grid-cols-2 gap-3 mb-3">
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Batch Number (auto-generated if empty)</label>
                    <input
                        v-model="form.batch_number"
                        type="text"
                        placeholder="e.g. BATCH-20260312-0001"
                        class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-dark-border rounded-md bg-white dark:bg-dark-card text-gray-900 dark:text-gray-100 focus:ring-primary-400 focus:border-primary-400"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Quantity *</label>
                    <input
                        v-model="form.quantity"
                        type="number"
                        min="0"
                        required
                        class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-dark-border rounded-md bg-white dark:bg-dark-card text-gray-900 dark:text-gray-100 focus:ring-primary-400 focus:border-primary-400"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Manufactured Date</label>
                    <input
                        v-model="form.manufactured_date"
                        type="date"
                        class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-dark-border rounded-md bg-white dark:bg-dark-card text-gray-900 dark:text-gray-100 focus:ring-primary-400 focus:border-primary-400"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Expiry Date</label>
                    <input
                        v-model="form.expiry_date"
                        type="date"
                        class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-dark-border rounded-md bg-white dark:bg-dark-card text-gray-900 dark:text-gray-100 focus:ring-primary-400 focus:border-primary-400"
                    />
                </div>
            </div>
            <div class="mb-3">
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Notes</label>
                <textarea
                    v-model="form.notes"
                    rows="2"
                    class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-dark-border rounded-md bg-white dark:bg-dark-card text-gray-900 dark:text-gray-100 focus:ring-primary-400 focus:border-primary-400"
                ></textarea>
            </div>
            <button
                @click="createBatch"
                :disabled="loading || !form.quantity"
                class="px-4 py-2 bg-primary-400 text-white text-sm font-medium rounded-md hover:bg-primary-500 transition disabled:opacity-50"
            >
                {{ loading ? 'Creating...' : 'Create Batch' }}
            </button>
        </div>

        <!-- Batches Table -->
        <div v-if="localBatches.length > 0" class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-dark-border">
                        <th class="text-left py-2 px-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Batch #</th>
                        <th class="text-left py-2 px-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Qty</th>
                        <th class="text-left py-2 px-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Manufactured</th>
                        <th class="text-left py-2 px-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Expiry</th>
                        <th class="text-left py-2 px-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="batch in localBatches"
                        :key="batch.id"
                        class="border-b border-gray-100 dark:border-dark-border/50 hover:bg-gray-50 dark:hover:bg-dark-bg/30"
                    >
                        <td class="py-2 px-3 font-mono text-gray-900 dark:text-gray-100">{{ batch.batch_number }}</td>
                        <td class="py-2 px-3 text-gray-900 dark:text-gray-100">{{ batch.quantity }}</td>
                        <td class="py-2 px-3 text-gray-600 dark:text-gray-400">{{ batch.manufactured_date || '-' }}</td>
                        <td class="py-2 px-3">
                            <span v-if="batch.expiry_date" :class="[
                                isExpired(batch.expiry_date)
                                    ? 'text-red-600 dark:text-red-400 font-medium'
                                    : 'text-gray-600 dark:text-gray-400'
                            ]">
                                {{ batch.expiry_date }}
                                <span v-if="isExpired(batch.expiry_date)" class="text-xs ml-1">(expired)</span>
                            </span>
                            <span v-else class="text-gray-400">-</span>
                        </td>
                        <td class="py-2 px-3 text-gray-600 dark:text-gray-400 max-w-[200px] truncate">{{ batch.notes || '-' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="text-center py-8 text-gray-500 dark:text-gray-400">
            <svg class="w-12 h-12 mx-auto mb-3 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
            </svg>
            <p>No batches recorded yet.</p>
            <p class="text-sm mt-1">Click "Add Batch" to record the first batch.</p>
        </div>
    </div>
</template>
