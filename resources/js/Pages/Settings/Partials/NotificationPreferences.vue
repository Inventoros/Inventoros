<script setup>
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    preferences: Object,
});

const form = useForm({
    email_enabled: props.preferences?.email_enabled ?? true,
    email_low_stock: props.preferences?.email_low_stock ?? true,
    email_orders: props.preferences?.email_orders ?? true,
    email_approvals: props.preferences?.email_approvals ?? true,
});

const submit = () => {
    form.patch(route('settings.account.update.notifications'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <div class="bg-white dark:bg-dark-card shadow sm:rounded-lg">
        <div class="px-6 py-5 border-b border-gray-200 dark:border-dark-border">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                Email Notification Preferences
            </h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Control which email notifications you receive. You'll still receive in-app notifications even if emails are disabled.
            </p>
        </div>

        <form @submit.prevent="submit" class="px-6 py-5">
            <div class="space-y-6">
                <!-- Master Toggle -->
                <div class="flex items-start">
                    <div class="flex items-center h-5">
                        <input
                            v-model="form.email_enabled"
                            type="checkbox"
                            id="email_enabled"
                            class="h-4 w-4 text-primary-400 border-gray-300 dark:border-dark-border rounded focus:ring-primary-400"
                        />
                    </div>
                    <div class="ml-3">
                        <label for="email_enabled" class="font-medium text-gray-900 dark:text-gray-100">
                            Enable Email Notifications
                        </label>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Master switch for all email notifications. Disable this to stop receiving any emails.
                        </p>
                    </div>
                </div>

                <!-- Divider -->
                <div class="border-t border-gray-200 dark:border-dark-border"></div>

                <!-- Individual Preferences -->
                <div class="space-y-4">
                    <h4 class="text-sm font-medium text-gray-900 dark:text-gray-100">
                        Notification Types
                    </h4>

                    <!-- Low Stock Alerts -->
                    <div class="flex items-start pl-4">
                        <div class="flex items-center h-5">
                            <input
                                v-model="form.email_low_stock"
                                type="checkbox"
                                id="email_low_stock"
                                :disabled="!form.email_enabled"
                                class="h-4 w-4 text-primary-400 border-gray-300 dark:border-dark-border rounded focus:ring-primary-400 disabled:opacity-50"
                            />
                        </div>
                        <div class="ml-3">
                            <label for="email_low_stock" class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                Low Stock Alerts
                            </label>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Receive emails when products reach low stock or out of stock levels.
                            </p>
                        </div>
                    </div>

                    <!-- Order Notifications -->
                    <div class="flex items-start pl-4">
                        <div class="flex items-center h-5">
                            <input
                                v-model="form.email_orders"
                                type="checkbox"
                                id="email_orders"
                                :disabled="!form.email_enabled"
                                class="h-4 w-4 text-primary-400 border-gray-300 dark:border-dark-border rounded focus:ring-primary-400 disabled:opacity-50"
                            />
                        </div>
                        <div class="ml-3">
                            <label for="email_orders" class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                Order Notifications
                            </label>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Receive emails about order status changes and updates.
                            </p>
                        </div>
                    </div>

                    <!-- Approval Notifications -->
                    <div class="flex items-start pl-4">
                        <div class="flex items-center h-5">
                            <input
                                v-model="form.email_approvals"
                                type="checkbox"
                                id="email_approvals"
                                :disabled="!form.email_enabled"
                                class="h-4 w-4 text-primary-400 border-gray-300 dark:border-dark-border rounded focus:ring-primary-400 disabled:opacity-50"
                            />
                        </div>
                        <div class="ml-3">
                            <label for="email_approvals" class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                Order Approvals
                            </label>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Receive emails when orders are approved or rejected.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="flex justify-end mt-6 pt-6 border-t border-gray-200 dark:border-dark-border">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="px-4 py-2 bg-primary-400 text-white rounded-md hover:bg-primary-500 disabled:opacity-50 disabled:cursor-not-allowed transition"
                >
                    {{ form.processing ? 'Saving...' : 'Save Preferences' }}
                </button>
            </div>
        </form>
    </div>
</template>
