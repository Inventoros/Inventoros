<script setup>
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Button from '@/Components/ui/Button.vue';

const { t } = useI18n();

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

const checkbox = 'h-4 w-4 rounded border-border-subtle bg-surface-canvas text-brand ds-focus-ring disabled:opacity-50';
</script>

<template>
    <div class="rounded-lg border border-border-subtle bg-surface-overlay shadow-xs">
        <div class="border-b border-border-subtle px-6 py-5">
            <h3 class="text-lg font-medium text-text-primary">
                {{ t('settings.notificationPreferences.title') }}
            </h3>
            <p class="mt-1 text-sm text-text-secondary">
                {{ t('settings.notificationPreferences.description') }}
            </p>
        </div>

        <form @submit.prevent="submit" class="px-6 py-5">
            <div class="space-y-6">
                <!-- Master Toggle -->
                <div class="flex items-start">
                    <div class="flex h-5 items-center">
                        <input
                            v-model="form.email_enabled"
                            type="checkbox"
                            id="email_enabled"
                            :class="checkbox"
                        />
                    </div>
                    <div class="ml-3">
                        <label for="email_enabled" class="font-medium text-text-primary">
                            {{ t('settings.notificationPreferences.enableEmail') }}
                        </label>
                        <p class="text-sm text-text-secondary">
                            {{ t('settings.notificationPreferences.masterSwitch') }}
                        </p>
                    </div>
                </div>

                <!-- Divider -->
                <div class="border-t border-border-subtle"></div>

                <!-- Individual Preferences -->
                <div class="space-y-4">
                    <h4 class="text-sm font-medium text-text-primary">
                        {{ t('settings.notificationPreferences.types') }}
                    </h4>

                    <!-- Low Stock Alerts -->
                    <div class="flex items-start pl-4">
                        <div class="flex h-5 items-center">
                            <input
                                v-model="form.email_low_stock"
                                type="checkbox"
                                id="email_low_stock"
                                :disabled="!form.email_enabled"
                                :class="checkbox"
                            />
                        </div>
                        <div class="ml-3">
                            <label for="email_low_stock" class="text-sm font-medium text-text-primary">
                                {{ t('settings.notificationPreferences.lowStock') }}
                            </label>
                            <p class="text-sm text-text-secondary">
                                {{ t('settings.notificationPreferences.lowStockDesc') }}
                            </p>
                        </div>
                    </div>

                    <!-- Order Notifications -->
                    <div class="flex items-start pl-4">
                        <div class="flex h-5 items-center">
                            <input
                                v-model="form.email_orders"
                                type="checkbox"
                                id="email_orders"
                                :disabled="!form.email_enabled"
                                :class="checkbox"
                            />
                        </div>
                        <div class="ml-3">
                            <label for="email_orders" class="text-sm font-medium text-text-primary">
                                {{ t('settings.notificationPreferences.orders') }}
                            </label>
                            <p class="text-sm text-text-secondary">
                                {{ t('settings.notificationPreferences.ordersDesc') }}
                            </p>
                        </div>
                    </div>

                    <!-- Approval Notifications -->
                    <div class="flex items-start pl-4">
                        <div class="flex h-5 items-center">
                            <input
                                v-model="form.email_approvals"
                                type="checkbox"
                                id="email_approvals"
                                :disabled="!form.email_enabled"
                                :class="checkbox"
                            />
                        </div>
                        <div class="ml-3">
                            <label for="email_approvals" class="text-sm font-medium text-text-primary">
                                {{ t('settings.notificationPreferences.approvals') }}
                            </label>
                            <p class="text-sm text-text-secondary">
                                {{ t('settings.notificationPreferences.approvalsDesc') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="mt-6 flex justify-end border-t border-border-subtle pt-6">
                <Button type="submit" variant="default" :loading="form.processing" :disabled="form.processing">
                    {{ form.processing ? t('common.saving') : t('settings.account.savePreferences') }}
                </Button>
            </div>
        </form>
    </div>
</template>
