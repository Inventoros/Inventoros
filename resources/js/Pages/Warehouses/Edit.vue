<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { usePermissions } from '@/composables/usePermissions';
import { ref, computed } from 'vue';

const { t } = useI18n();
const { hasPermission } = usePermissions();

const props = defineProps({
    warehouse: Object,
    users: Array,
    assignedUserIds: Array,
});

const form = useForm({
    name: props.warehouse.name || '',
    code: props.warehouse.code || '',
    description: props.warehouse.description || '',
    address_line_1: props.warehouse.address_line_1 || '',
    address_line_2: props.warehouse.address_line_2 || '',
    city: props.warehouse.city || '',
    province: props.warehouse.province || '',
    postal_code: props.warehouse.postal_code || '',
    country: props.warehouse.country || 'Canada',
    phone: props.warehouse.phone || '',
    email: props.warehouse.email || '',
    manager_name: props.warehouse.manager_name || '',
    timezone: props.warehouse.timezone || 'America/Toronto',
    currency: props.warehouse.currency || 'CAD',
    priority: props.warehouse.priority ?? 0,
    is_active: props.warehouse.is_active ?? true,
    user_ids: props.assignedUserIds || [],
});

const timezones = [
    { value: 'America/St_Johns', label: "Newfoundland (St. John's)" },
    { value: 'America/Halifax', label: 'Atlantic (Halifax)' },
    { value: 'America/Toronto', label: 'Eastern (Toronto)' },
    { value: 'America/Winnipeg', label: 'Central (Winnipeg)' },
    { value: 'America/Edmonton', label: 'Mountain (Edmonton)' },
    { value: 'America/Vancouver', label: 'Pacific (Vancouver)' },
];

const currencies = [
    { value: 'CAD', label: 'CAD - Canadian Dollar' },
    { value: 'USD', label: 'USD - US Dollar' },
    { value: 'EUR', label: 'EUR - Euro' },
    { value: 'GBP', label: 'GBP - British Pound' },
];

const userSearch = ref('');

const filteredUsers = computed(() => {
    if (!props.users) return [];
    if (!userSearch.value) return props.users;
    const query = userSearch.value.toLowerCase();
    return props.users.filter(user =>
        user.name.toLowerCase().includes(query) ||
        user.email.toLowerCase().includes(query)
    );
});

const toggleUser = (userId) => {
    const index = form.user_ids.indexOf(userId);
    if (index === -1) {
        form.user_ids.push(userId);
    } else {
        form.user_ids.splice(index, 1);
    }
};

const submit = () => {
    form.put(route('warehouses.update', props.warehouse.id));
};
</script>

<template>
    <Head title="Edit Warehouse" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                    Edit Warehouse: {{ warehouse.name }}
                </h2>
                <Link
                    :href="route('warehouses.index')"
                    class="inline-flex items-center px-4 py-2 bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                >
                    Back to Warehouses
                </Link>
            </div>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <form @submit.prevent="submit" class="p-6 space-y-6">
                        <!-- General -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">General</h3>
                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel for="name" value="Warehouse Name" />
                                    <TextInput
                                        id="name"
                                        v-model="form.name"
                                        type="text"
                                        class="mt-1 block w-full"
                                        required
                                        placeholder="e.g., Main Warehouse"
                                    />
                                    <InputError :message="form.errors.name" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="code" value="Warehouse Code" />
                                    <TextInput
                                        id="code"
                                        v-model="form.code"
                                        type="text"
                                        class="mt-1 block w-full"
                                        required
                                        placeholder="e.g., WH-MAIN"
                                    />
                                    <InputError :message="form.errors.code" class="mt-2" />
                                </div>

                                <div class="md:col-span-2">
                                    <InputLabel for="description" value="Description" />
                                    <textarea
                                        id="description"
                                        v-model="form.description"
                                        rows="3"
                                        class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                        placeholder="Optional description..."
                                    ></textarea>
                                    <InputError :message="form.errors.description" class="mt-2" />
                                </div>
                            </div>
                        </div>

                        <!-- Address -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Address</h3>
                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div class="md:col-span-2">
                                    <InputLabel for="address_line_1" value="Address Line 1" />
                                    <TextInput
                                        id="address_line_1"
                                        v-model="form.address_line_1"
                                        type="text"
                                        class="mt-1 block w-full"
                                        placeholder="Street address"
                                    />
                                    <InputError :message="form.errors.address_line_1" class="mt-2" />
                                </div>

                                <div class="md:col-span-2">
                                    <InputLabel for="address_line_2" value="Address Line 2" />
                                    <TextInput
                                        id="address_line_2"
                                        v-model="form.address_line_2"
                                        type="text"
                                        class="mt-1 block w-full"
                                        placeholder="Suite, unit, building, floor, etc."
                                    />
                                    <InputError :message="form.errors.address_line_2" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="city" value="City" />
                                    <TextInput
                                        id="city"
                                        v-model="form.city"
                                        type="text"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.city" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="province" value="Province / State" />
                                    <TextInput
                                        id="province"
                                        v-model="form.province"
                                        type="text"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.province" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="postal_code" value="Postal Code" />
                                    <TextInput
                                        id="postal_code"
                                        v-model="form.postal_code"
                                        type="text"
                                        class="mt-1 block w-full"
                                        placeholder="e.g., M5V 2T6"
                                    />
                                    <InputError :message="form.errors.postal_code" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="country" value="Country" />
                                    <TextInput
                                        id="country"
                                        v-model="form.country"
                                        type="text"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.country" class="mt-2" />
                                </div>
                            </div>
                        </div>

                        <!-- Contact -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Contact</h3>
                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel for="phone" value="Phone" />
                                    <TextInput
                                        id="phone"
                                        v-model="form.phone"
                                        type="text"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.phone" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="email" value="Email" />
                                    <TextInput
                                        id="email"
                                        v-model="form.email"
                                        type="email"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.email" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="manager_name" value="Manager Name" />
                                    <TextInput
                                        id="manager_name"
                                        v-model="form.manager_name"
                                        type="text"
                                        class="mt-1 block w-full"
                                    />
                                    <InputError :message="form.errors.manager_name" class="mt-2" />
                                </div>
                            </div>
                        </div>

                        <!-- Settings -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Settings</h3>
                            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel for="timezone" value="Timezone" />
                                    <select
                                        id="timezone"
                                        v-model="form.timezone"
                                        class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    >
                                        <option v-for="tz in timezones" :key="tz.value" :value="tz.value">
                                            {{ tz.label }}
                                        </option>
                                    </select>
                                    <InputError :message="form.errors.timezone" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="currency" value="Currency" />
                                    <select
                                        id="currency"
                                        v-model="form.currency"
                                        class="mt-1 block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 shadow-sm focus:border-primary-400 focus:ring-primary-400"
                                    >
                                        <option v-for="cur in currencies" :key="cur.value" :value="cur.value">
                                            {{ cur.label }}
                                        </option>
                                    </select>
                                    <InputError :message="form.errors.currency" class="mt-2" />
                                </div>

                                <div>
                                    <InputLabel for="priority" value="Priority" />
                                    <TextInput
                                        id="priority"
                                        v-model="form.priority"
                                        type="number"
                                        class="mt-1 block w-full"
                                        min="0"
                                        placeholder="0"
                                    />
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Higher priority warehouses are used first for fulfillment.</p>
                                    <InputError :message="form.errors.priority" class="mt-2" />
                                </div>

                                <div class="flex items-center pt-6">
                                    <label class="flex items-center">
                                        <input
                                            type="checkbox"
                                            v-model="form.is_active"
                                            class="rounded border-gray-300 dark:border-dark-border text-primary-400 shadow-sm focus:ring-primary-400"
                                        />
                                        <span class="ml-2 text-sm text-gray-600 dark:text-gray-300">Active</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- User Access -->
                        <div v-if="hasPermission('manage_warehouse_users') && users">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">User Access</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                                Select which users have access to this warehouse. Users without access will not see this warehouse or its inventory.
                            </p>

                            <!-- User search -->
                            <div class="mb-4">
                                <input
                                    v-model="userSearch"
                                    type="text"
                                    placeholder="Search users..."
                                    class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 placeholder-gray-500 shadow-sm focus:border-primary-400 focus:ring-primary-400 text-sm"
                                />
                            </div>

                            <div class="border border-gray-200 dark:border-dark-border rounded-lg max-h-64 overflow-y-auto">
                                <div
                                    v-for="user in filteredUsers"
                                    :key="user.id"
                                    class="flex items-center px-4 py-3 border-b border-gray-200 dark:border-dark-border last:border-0 hover:bg-gray-50 dark:hover:bg-dark-bg/50"
                                >
                                    <label class="flex items-center flex-1 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            :checked="form.user_ids.includes(user.id)"
                                            @change="toggleUser(user.id)"
                                            class="rounded border-gray-300 dark:border-dark-border text-primary-400 shadow-sm focus:ring-primary-400"
                                        />
                                        <div class="ml-3">
                                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ user.name }}</div>
                                            <div class="text-sm text-gray-500 dark:text-gray-400">{{ user.email }}</div>
                                        </div>
                                    </label>
                                </div>
                                <div v-if="filteredUsers.length === 0" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    No users found.
                                </div>
                            </div>
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                {{ form.user_ids.length }} user(s) selected
                            </p>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center justify-end gap-4 pt-6 border-t border-gray-200 dark:border-dark-border">
                            <Link
                                :href="route('warehouses.index')"
                                class="inline-flex items-center px-4 py-2 bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-100 dark:hover:bg-dark-bg/50"
                            >
                                Cancel
                            </Link>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500 focus:bg-primary-500 active:bg-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-400 focus:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-50"
                            >
                                Update Warehouse
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
