<script setup>
import { ref, computed } from 'vue';
import axios from 'axios';

const props = defineProps({
    productId: Number,
    serials: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['serial-created', 'serial-updated']);

const localSerials = ref([...props.serials]);
const showForm = ref(false);
const loading = ref(false);
const error = ref(null);
const statusFilter = ref('all');

const form = ref({
    serial_number: '',
    status: 'available',
    notes: '',
});

const filteredSerials = computed(() => {
    if (statusFilter.value === 'all') return localSerials.value;
    return localSerials.value.filter(s => s.status === statusFilter.value);
});

const statusCounts = computed(() => {
    const counts = { available: 0, sold: 0, reserved: 0, damaged: 0 };
    localSerials.value.forEach(s => {
        if (counts[s.status] !== undefined) counts[s.status]++;
    });
    return counts;
});

const resetForm = () => {
    form.value = { serial_number: '', status: 'available', notes: '' };
    error.value = null;
};

const createSerial = async () => {
    loading.value = true;
    error.value = null;

    try {
        const payload = {
            serial_number: form.value.serial_number,
            status: form.value.status,
        };
        if (form.value.notes) payload.notes = form.value.notes;

        const response = await axios.post(`/api/v1/products/${props.productId}/serials`, payload);
        localSerials.value.unshift(response.data.data);
        emit('serial-created', response.data.data);
        resetForm();
        showForm.value = false;
    } catch (err) {
        if (err.response?.data?.errors) {
            error.value = Object.values(err.response.data.errors).flat().join(', ');
        } else {
            error.value = err.response?.data?.message || 'Failed to create serial';
        }
    } finally {
        loading.value = false;
    }
};

const updateStatus = async (serial, newStatus) => {
    try {
        const response = await axios.put(`/api/v1/products/${props.productId}/serials/${serial.id}`, {
            status: newStatus,
        });
        const index = localSerials.value.findIndex(s => s.id === serial.id);
        if (index !== -1) {
            localSerials.value[index] = response.data.data;
        }
        emit('serial-updated', response.data.data);
    } catch (err) {
        alert(err.response?.data?.message || 'Failed to update status');
    }
};

const statusBadgeClass = (status) => {
    const classes = {
        available: 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
        sold: 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300',
        reserved: 'bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300',
        damaged: 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300',
    };
    return classes[status] || 'bg-gray-100 text-gray-800';
};
</script>

<template>
    <div>
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                Serial Number Tracking
            </h3>
            <button
                @click="showForm = !showForm"
                class="inline-flex items-center px-3 py-1.5 bg-primary-400 text-white text-sm font-medium rounded-md hover:bg-primary-500 transition"
            >
                <svg v-if="!showForm" class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                {{ showForm ? 'Cancel' : 'Add Serial' }}
            </button>
        </div>

        <!-- Status Summary -->
        <div v-if="localSerials.length > 0" class="flex gap-2 mb-4 flex-wrap">
            <button
                @click="statusFilter = 'all'"
                :class="[
                    'px-2 py-1 text-xs rounded-full border transition',
                    statusFilter === 'all'
                        ? 'bg-gray-200 dark:bg-gray-700 border-gray-400 dark:border-gray-500 text-gray-900 dark:text-gray-100'
                        : 'border-gray-200 dark:border-dark-border text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-dark-bg/50'
                ]"
            >
                All ({{ localSerials.length }})
            </button>
            <button
                v-for="(count, status) in statusCounts"
                :key="status"
                @click="statusFilter = status"
                :class="[
                    'px-2 py-1 text-xs rounded-full border transition capitalize',
                    statusFilter === status
                        ? statusBadgeClass(status) + ' border-transparent'
                        : 'border-gray-200 dark:border-dark-border text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-dark-bg/50'
                ]"
            >
                {{ status }} ({{ count }})
            </button>
        </div>

        <!-- Add Serial Form -->
        <div v-if="showForm" class="mb-4 p-4 bg-gray-50 dark:bg-dark-bg/50 rounded-lg border border-gray-200 dark:border-dark-border">
            <div v-if="error" class="mb-3 p-2 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 text-sm rounded">
                {{ error }}
            </div>
            <div class="grid grid-cols-2 gap-3 mb-3">
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Serial Number *</label>
                    <input
                        v-model="form.serial_number"
                        type="text"
                        required
                        placeholder="e.g. SN-2026-00001"
                        class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-dark-border rounded-md bg-white dark:bg-dark-card text-gray-900 dark:text-gray-100 focus:ring-primary-400 focus:border-primary-400"
                    />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Status</label>
                    <select
                        v-model="form.status"
                        class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-dark-border rounded-md bg-white dark:bg-dark-card text-gray-900 dark:text-gray-100 focus:ring-primary-400 focus:border-primary-400"
                    >
                        <option value="available">Available</option>
                        <option value="sold">Sold</option>
                        <option value="reserved">Reserved</option>
                        <option value="damaged">Damaged</option>
                    </select>
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
                @click="createSerial"
                :disabled="loading || !form.serial_number"
                class="px-4 py-2 bg-primary-400 text-white text-sm font-medium rounded-md hover:bg-primary-500 transition disabled:opacity-50"
            >
                {{ loading ? 'Creating...' : 'Create Serial' }}
            </button>
        </div>

        <!-- Serials Table -->
        <div v-if="filteredSerials.length > 0" class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-dark-border">
                        <th class="text-left py-2 px-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Serial #</th>
                        <th class="text-left py-2 px-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Status</th>
                        <th class="text-left py-2 px-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Notes</th>
                        <th class="text-left py-2 px-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="serial in filteredSerials"
                        :key="serial.id"
                        class="border-b border-gray-100 dark:border-dark-border/50 hover:bg-gray-50 dark:hover:bg-dark-bg/30"
                    >
                        <td class="py-2 px-3 font-mono text-gray-900 dark:text-gray-100">{{ serial.serial_number }}</td>
                        <td class="py-2 px-3">
                            <span :class="['px-2 py-0.5 text-xs font-medium rounded-full capitalize', statusBadgeClass(serial.status)]">
                                {{ serial.status }}
                            </span>
                        </td>
                        <td class="py-2 px-3 text-gray-600 dark:text-gray-400 max-w-[200px] truncate">{{ serial.notes || '-' }}</td>
                        <td class="py-2 px-3">
                            <div class="flex gap-1">
                                <button
                                    v-if="serial.status === 'available'"
                                    @click="updateStatus(serial, 'sold')"
                                    class="px-2 py-0.5 text-xs bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded hover:bg-blue-200 dark:hover:bg-blue-900/50 transition"
                                    title="Mark as sold"
                                >
                                    Sold
                                </button>
                                <button
                                    v-if="serial.status === 'available'"
                                    @click="updateStatus(serial, 'reserved')"
                                    class="px-2 py-0.5 text-xs bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 rounded hover:bg-amber-200 dark:hover:bg-amber-900/50 transition"
                                    title="Mark as reserved"
                                >
                                    Reserve
                                </button>
                                <button
                                    v-if="serial.status !== 'damaged'"
                                    @click="updateStatus(serial, 'damaged')"
                                    class="px-2 py-0.5 text-xs bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 rounded hover:bg-red-200 dark:hover:bg-red-900/50 transition"
                                    title="Mark as damaged"
                                >
                                    Damaged
                                </button>
                                <button
                                    v-if="serial.status !== 'available'"
                                    @click="updateStatus(serial, 'available')"
                                    class="px-2 py-0.5 text-xs bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded hover:bg-green-200 dark:hover:bg-green-900/50 transition"
                                    title="Mark as available"
                                >
                                    Available
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else-if="localSerials.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
            <svg class="w-12 h-12 mx-auto mb-3 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
            </svg>
            <p>No serial numbers recorded yet.</p>
            <p class="text-sm mt-1">Click "Add Serial" to register the first serial number.</p>
        </div>

        <div v-else class="text-center py-4 text-gray-500 dark:text-gray-400 text-sm">
            No serials matching the selected filter.
        </div>
    </div>
</template>
