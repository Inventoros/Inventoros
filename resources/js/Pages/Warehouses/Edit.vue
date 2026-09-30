<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { usePermissions } from '@/composables/usePermissions';
import { ref, computed } from 'vue';
import { ArrowLeft } from 'lucide-vue-next';

const { t } = useI18n();
const { hasPermission } = usePermissions();

const props = defineProps({
    warehouse: Object,
    users: { type: Array, default: () => [] },
    assignedUserIds: { type: Array, default: () => [] },
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
    capacity: props.warehouse.capacity ?? null,
    is_active: props.warehouse.is_active ?? true,
});

// User assignment saves separately: it has its own route and permission
// (manage_warehouse_users), independent of editing the warehouse details.
const usersForm = useForm({
    user_ids: [...props.assignedUserIds],
});

const timezones = [
    { value: 'America/St_Johns', label: t('warehouses.timezoneOptions.stJohns') },
    { value: 'America/Halifax', label: t('warehouses.timezoneOptions.halifax') },
    { value: 'America/Toronto', label: t('warehouses.timezoneOptions.toronto') },
    { value: 'America/Winnipeg', label: t('warehouses.timezoneOptions.winnipeg') },
    { value: 'America/Edmonton', label: t('warehouses.timezoneOptions.edmonton') },
    { value: 'America/Vancouver', label: t('warehouses.timezoneOptions.vancouver') },
];

const currencies = [
    { value: 'CAD', label: t('purchaseOrders.currencies.cad') },
    { value: 'USD', label: t('purchaseOrders.currencies.usd') },
    { value: 'EUR', label: t('purchaseOrders.currencies.eur') },
    { value: 'GBP', label: t('purchaseOrders.currencies.gbp') },
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
    const index = usersForm.user_ids.indexOf(userId);
    if (index === -1) {
        usersForm.user_ids.push(userId);
    } else {
        usersForm.user_ids.splice(index, 1);
    }
};

const submit = () => {
    form.put(route('warehouses.update', props.warehouse.id));
};

const saveUsers = () => {
    usersForm.post(route('warehouses.users.update', props.warehouse.id), {
        preserveScroll: true,
    });
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head :title="t('warehouses.editWarehouse')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('warehouses.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('warehouses.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.warehouses') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('common.editWithName', { name: warehouse.name }) }}</span>
            </div>
        </template>

        <PageHeader :title="t('warehouses.edit.title', { name: warehouse.name })" :description="t('warehouses.edit.subtitle')">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('warehouses.index')">
                    <ArrowLeft :size="14" />
                    {{ t('warehouses.backToWarehouses') }}
                </Button>
            </template>
        </PageHeader>

        <form v-if="hasPermission('edit_warehouses')" @submit.prevent="submit" class="mt-6 space-y-4">
            <!-- General -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('warehouses.sections.general') }}</h3></div>
                <div class="p-5">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label for="name" :class="fieldLabel">{{ t('warehouses.fields.name') }}</label>
                            <input id="name" v-model="form.name" type="text" :class="fieldInput" required :placeholder="t('warehouses.placeholders.name')" />
                            <p v-if="form.errors.name" :class="fieldError">{{ form.errors.name }}</p>
                        </div>

                        <div>
                            <label for="code" :class="fieldLabel">{{ t('warehouses.fields.code') }}</label>
                            <input id="code" v-model="form.code" type="text" :class="fieldInput" required :placeholder="t('warehouses.placeholders.code')" />
                            <p v-if="form.errors.code" :class="fieldError">{{ form.errors.code }}</p>
                        </div>

                        <div class="md:col-span-2">
                            <label for="description" :class="fieldLabel">{{ t('warehouses.fields.description') }}</label>
                            <textarea id="description" v-model="form.description" rows="3" :class="fieldArea" :placeholder="t('warehouses.placeholders.description')"></textarea>
                            <p v-if="form.errors.description" :class="fieldError">{{ form.errors.description }}</p>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Address -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('warehouses.sections.address') }}</h3></div>
                <div class="p-5">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <label for="address_line_1" :class="fieldLabel">{{ t('warehouses.fields.addressLine1') }}</label>
                            <input id="address_line_1" v-model="form.address_line_1" type="text" :class="fieldInput" :placeholder="t('warehouses.placeholders.addressLine1')" />
                            <p v-if="form.errors.address_line_1" :class="fieldError">{{ form.errors.address_line_1 }}</p>
                        </div>

                        <div class="md:col-span-2">
                            <label for="address_line_2" :class="fieldLabel">{{ t('warehouses.fields.addressLine2') }}</label>
                            <input id="address_line_2" v-model="form.address_line_2" type="text" :class="fieldInput" :placeholder="t('warehouses.placeholders.addressLine2')" />
                            <p v-if="form.errors.address_line_2" :class="fieldError">{{ form.errors.address_line_2 }}</p>
                        </div>

                        <div>
                            <label for="city" :class="fieldLabel">{{ t('warehouses.fields.city') }}</label>
                            <input id="city" v-model="form.city" type="text" :class="fieldInput" />
                            <p v-if="form.errors.city" :class="fieldError">{{ form.errors.city }}</p>
                        </div>

                        <div>
                            <label for="province" :class="fieldLabel">{{ t('warehouses.fields.province') }}</label>
                            <input id="province" v-model="form.province" type="text" :class="fieldInput" />
                            <p v-if="form.errors.province" :class="fieldError">{{ form.errors.province }}</p>
                        </div>

                        <div>
                            <label for="postal_code" :class="fieldLabel">{{ t('warehouses.fields.postalCode') }}</label>
                            <input id="postal_code" v-model="form.postal_code" type="text" :class="fieldInput" :placeholder="t('warehouses.placeholders.postalCode')" />
                            <p v-if="form.errors.postal_code" :class="fieldError">{{ form.errors.postal_code }}</p>
                        </div>

                        <div>
                            <label for="country" :class="fieldLabel">{{ t('warehouses.fields.country') }}</label>
                            <input id="country" v-model="form.country" type="text" :class="fieldInput" />
                            <p v-if="form.errors.country" :class="fieldError">{{ form.errors.country }}</p>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Contact -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('warehouses.sections.contact') }}</h3></div>
                <div class="p-5">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label for="phone" :class="fieldLabel">{{ t('warehouses.fields.phone') }}</label>
                            <input id="phone" v-model="form.phone" type="text" :class="fieldInput" />
                            <p v-if="form.errors.phone" :class="fieldError">{{ form.errors.phone }}</p>
                        </div>

                        <div>
                            <label for="email" :class="fieldLabel">{{ t('warehouses.fields.email') }}</label>
                            <input id="email" v-model="form.email" type="email" :class="fieldInput" />
                            <p v-if="form.errors.email" :class="fieldError">{{ form.errors.email }}</p>
                        </div>

                        <div>
                            <label for="manager_name" :class="fieldLabel">{{ t('warehouses.fields.managerName') }}</label>
                            <input id="manager_name" v-model="form.manager_name" type="text" :class="fieldInput" />
                            <p v-if="form.errors.manager_name" :class="fieldError">{{ form.errors.manager_name }}</p>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Settings -->
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('warehouses.sections.settings') }}</h3></div>
                <div class="p-5">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label for="timezone" :class="fieldLabel">{{ t('warehouses.fields.timezone') }}</label>
                            <select id="timezone" v-model="form.timezone" :class="fieldInput">
                                <option v-for="tz in timezones" :key="tz.value" :value="tz.value">
                                    {{ tz.label }}
                                </option>
                            </select>
                            <p v-if="form.errors.timezone" :class="fieldError">{{ form.errors.timezone }}</p>
                        </div>

                        <div>
                            <label for="currency" :class="fieldLabel">{{ t('warehouses.fields.currency') }}</label>
                            <select id="currency" v-model="form.currency" :class="fieldInput">
                                <option v-for="cur in currencies" :key="cur.value" :value="cur.value">
                                    {{ cur.label }}
                                </option>
                            </select>
                            <p v-if="form.errors.currency" :class="fieldError">{{ form.errors.currency }}</p>
                        </div>

                        <div>
                            <label for="priority" :class="fieldLabel">{{ t('warehouses.fields.priority') }}</label>
                            <input id="priority" v-model="form.priority" type="number" min="0" :class="fieldInput" placeholder="0" />
                            <p class="mt-1 text-xs text-text-tertiary">{{ t('warehouses.fields.priorityHint') }}</p>
                            <p v-if="form.errors.priority" :class="fieldError">{{ form.errors.priority }}</p>
                        </div>

                        <div>
                            <label for="capacity" :class="fieldLabel">{{ t('warehouses.fields.capacity') }}</label>
                            <input id="capacity" v-model.number="form.capacity" type="number" min="0" :class="fieldInput" />
                            <p class="mt-1 text-xs text-text-tertiary">{{ t('warehouses.fields.capacityHint') }}</p>
                            <p v-if="form.errors.capacity" :class="fieldError">{{ form.errors.capacity }}</p>
                        </div>

                        <div class="flex items-center pt-6">
                            <label class="flex items-center">
                                <input
                                    type="checkbox"
                                    v-model="form.is_active"
                                    class="rounded border-border-subtle bg-surface-canvas text-brand ds-focus-ring"
                                />
                                <span class="ml-2 text-sm text-text-secondary">{{ t('warehouses.fields.isActive') }}</span>
                            </label>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Actions -->
            <div class="flex items-center justify-end gap-2 border-t border-border-subtle pt-6">
                <Button variant="secondary" as="Link" :href="route('warehouses.index')">
                    {{ t('common.cancel') }}
                </Button>
                <Button type="submit" variant="default" :loading="form.processing" :disabled="form.processing">
                    {{ t('warehouses.updateWarehouse') }}
                </Button>
            </div>
        </form>

        <!-- User Access (separate form: its own route and permission) -->
        <form v-if="hasPermission('manage_warehouse_users')" id="user-access" @submit.prevent="saveUsers" class="mt-6">
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('warehouses.users.title') }}</h3></div>
                <div class="p-5">
                    <p class="mb-4 text-sm text-text-tertiary">{{ t('warehouses.users.hint') }}</p>

                    <div class="mb-4">
                        <input
                            v-model="userSearch"
                            type="text"
                            :placeholder="t('warehouses.users.searchPlaceholder')"
                            :aria-label="t('warehouses.users.searchPlaceholder')"
                            :class="fieldInput"
                        />
                    </div>

                    <div class="max-h-64 overflow-y-auto rounded-lg border border-border-subtle">
                        <label
                            v-for="user in filteredUsers"
                            :key="user.id"
                            class="flex cursor-pointer items-center gap-3 border-b border-border-subtle px-4 py-3 last:border-0 hover:bg-surface-sunken"
                        >
                            <input
                                type="checkbox"
                                :checked="usersForm.user_ids.includes(user.id)"
                                @change="toggleUser(user.id)"
                                class="rounded border-border-subtle bg-surface-canvas text-brand ds-focus-ring"
                            />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-text-primary">{{ user.name }}</span>
                                <span class="block truncate text-sm text-text-tertiary">{{ user.email }}</span>
                            </span>
                            <Badge v-if="user.has_all_warehouse_access" variant="neutral" size="sm">{{ t('warehouses.users.allWarehouses') }}</Badge>
                        </label>
                        <div v-if="filteredUsers.length === 0" class="px-4 py-6 text-center text-sm text-text-tertiary">
                            {{ t('warehouses.users.noUsers') }}
                        </div>
                    </div>
                    <p v-if="usersForm.errors.user_ids" :class="fieldError">{{ usersForm.errors.user_ids }}</p>

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs text-text-tertiary">
                            {{ t('warehouses.users.selected', { count: usersForm.user_ids.length }, usersForm.user_ids.length) }}
                        </p>
                        <Button type="submit" variant="default" size="sm" :loading="usersForm.processing" :disabled="usersForm.processing">
                            {{ t('warehouses.users.save') }}
                        </Button>
                    </div>
                </div>
            </Card>
        </form>
    </AppLayout>
</template>
