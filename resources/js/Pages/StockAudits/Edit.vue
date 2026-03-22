<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    audit: Object,
    locations: Array,
    auditTypes: Object,
});

const form = useForm({
    name: props.audit.name || '',
    description: props.audit.description || '',
    audit_type: props.audit.audit_type || 'cycle',
    warehouse_location_id: props.audit.warehouse_location_id || '',
    notes: props.audit.notes || '',
});

const submit = () => {
    form.put(route('stock-audits.update', props.audit.id));
};
</script>

<template>
    <Head title="Edit Stock Audit" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">Edit Stock Audit</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ audit.audit_number }} - {{ audit.name }}</p>
                </div>
                <Link
                    :href="route('stock-audits.show', audit.id)"
                    class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                >
                    Back to Audit
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
                <form @submit.prevent="submit" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-6">Audit Details</h3>

                    <div class="space-y-6">
                        <!-- Name -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Audit Name <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="form.name"
                                type="text"
                                required
                                maxlength="255"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                :class="{ 'border-red-500': form.errors.name }"
                            />
                            <p v-if="form.errors.name" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                {{ form.errors.name }}
                            </p>
                        </div>

                        <!-- Description -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Description
                            </label>
                            <textarea
                                v-model="form.description"
                                rows="3"
                                maxlength="1000"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                :class="{ 'border-red-500': form.errors.description }"
                                placeholder="Describe the purpose of this audit..."
                            ></textarea>
                            <p v-if="form.errors.description" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                {{ form.errors.description }}
                            </p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Audit Type -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Audit Type <span class="text-red-500">*</span>
                                </label>
                                <select
                                    v-model="form.audit_type"
                                    required
                                    class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    :class="{ 'border-red-500': form.errors.audit_type }"
                                >
                                    <option v-for="(label, value) in auditTypes" :key="value" :value="value">{{ label }}</option>
                                </select>
                                <p v-if="form.errors.audit_type" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                    {{ form.errors.audit_type }}
                                </p>
                            </div>

                            <!-- Location -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Warehouse Location
                                </label>
                                <select
                                    v-model="form.warehouse_location_id"
                                    class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    :class="{ 'border-red-500': form.errors.warehouse_location_id }"
                                >
                                    <option value="">All Locations</option>
                                    <option v-for="location in locations" :key="location.id" :value="location.id">
                                        {{ location.name }}
                                        <span v-if="location.code">({{ location.code }})</span>
                                    </option>
                                </select>
                                <p v-if="form.errors.warehouse_location_id" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                    {{ form.errors.warehouse_location_id }}
                                </p>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                Notes
                            </label>
                            <textarea
                                v-model="form.notes"
                                rows="3"
                                maxlength="2000"
                                class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                :class="{ 'border-red-500': form.errors.notes }"
                                placeholder="Additional notes or instructions..."
                            ></textarea>
                            <p v-if="form.errors.notes" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                {{ form.errors.notes }}
                            </p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="mt-8 flex gap-3 justify-end pt-6 border-t border-gray-200 dark:border-dark-border">
                        <Link
                            :href="route('stock-audits.show', audit.id)"
                            class="px-4 py-2 bg-gray-200 dark:bg-dark-bg hover:bg-gray-300 dark:hover:bg-dark-bg/70 text-gray-700 dark:text-gray-300 font-medium rounded-lg transition"
                        >
                            Cancel
                        </Link>
                        <button
                            type="submit"
                            :disabled="form.processing || !form.name"
                            class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white font-medium rounded-lg transition disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {{ form.processing ? 'Saving...' : 'Update Audit' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
