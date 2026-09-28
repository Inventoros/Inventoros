<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ExternalLink, Users } from 'lucide-vue-next';

import { useI18n } from 'vue-i18n';
const props = defineProps({
    organization: Object,
    user: Object,
    approvalSettings: { type: Object, default: () => ({}) },
    canManageOrganization: { type: Boolean, default: false },
    portal: { type: Object, default: () => ({ enabled: false, login_url: null }) },
});


const { t } = useI18n();
const activeTab = ref('general');

// General settings form
const generalForm = useForm({
    name: props.organization.name || '',
    email: props.organization.email || '',
    phone: props.organization.phone || '',
    address: props.organization.address || '',
    city: props.organization.city || '',
    state: props.organization.state || '',
    zip: props.organization.zip || '',
    country: props.organization.country || '',
});

const submitGeneral = () => {
    generalForm.patch(route('settings.organization.update.general'), {
        preserveScroll: true,
    });
};

// Regional settings form
const regionalForm = useForm({
    currency: props.organization.currency || 'USD',
    timezone: props.organization.timezone || 'UTC',
    date_format: props.organization.date_format || 'Y-m-d',
    time_format: props.organization.time_format || 'H:i',
});

const submitRegional = () => {
    regionalForm.patch(route('settings.organization.update.regional'), {
        preserveScroll: true,
    });
};

const isAdmin = props.user.is_admin;

// Customer portal on/off
const portalSaving = ref(false);
const setPortalEnabled = (enabled) => {
    router.patch(route('settings.organization.update.portal'), { portal_enabled: enabled }, {
        preserveScroll: true,
        onStart: () => { portalSaving.value = true; },
        onFinish: () => { portalSaving.value = false; },
    });
};

// Approval workflows. All off by default; a blank threshold means every
// request of that kind needs approval once the workflow is on.
const approvalsForm = useForm({
    purchase_orders_enabled: !!props.approvalSettings.purchase_orders_enabled,
    purchase_orders_threshold: props.approvalSettings.purchase_orders_threshold ?? '',
    stock_adjustments_enabled: !!props.approvalSettings.stock_adjustments_enabled,
    stock_adjustments_quantity_threshold: props.approvalSettings.stock_adjustments_quantity_threshold ?? '',
    stock_adjustments_value_threshold: props.approvalSettings.stock_adjustments_value_threshold ?? '',
    stock_transfers_enabled: !!props.approvalSettings.stock_transfers_enabled,
    admins_can_self_approve: props.approvalSettings.admins_can_self_approve ?? true,
});

const submitApprovals = () => {
    approvalsForm
        .transform((data) => ({
            ...data,
            purchase_orders_threshold: data.purchase_orders_threshold === '' ? null : data.purchase_orders_threshold,
            stock_adjustments_quantity_threshold: data.stock_adjustments_quantity_threshold === '' ? null : data.stock_adjustments_quantity_threshold,
            stock_adjustments_value_threshold: data.stock_adjustments_value_threshold === '' ? null : data.stock_adjustments_value_threshold,
        }))
        .patch(route('settings.organization.update.approvals'), { preserveScroll: true });
};

const toggleRow = 'flex items-start gap-3 min-h-11 py-2';
const toggleBox = 'mt-0.5 h-5 w-5 shrink-0 rounded border-border-strong text-brand ds-focus-ring disabled:opacity-60';

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring disabled:cursor-not-allowed disabled:opacity-60';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';

const tabs = [
    { key: 'general', label: 'General Information' },
    { key: 'regional', label: 'Regional Settings' },
];
</script>

<template>
    <Head :title="t('settings.organization.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('settings.account.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.sections.workspace') }}</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('settings.index')" class="text-text-tertiary hover:text-text-primary">{{ t('nav.settings') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('settings.organization.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('settings.organization.title')" description="Manage your organization's profile and regional preferences." />

        <!-- Tabs -->
        <div class="mt-6 border-b border-border-subtle">
            <nav class="ds-tabs -mb-px gap-6 sm:gap-8">
                <button
                    @click="activeTab = 'general'"
                    :class="[
                        'border-b-2 px-1 py-3 text-sm font-medium transition-colors',
                        activeTab === 'general'
                            ? 'border-brand text-brand'
                            : 'border-transparent text-text-tertiary hover:border-border-strong hover:text-text-secondary'
                    ]"
                >
                    General Information
                </button>
                <button
                    @click="activeTab = 'regional'"
                    :class="[
                        'border-b-2 px-1 py-3 text-sm font-medium transition-colors',
                        activeTab === 'regional'
                            ? 'border-brand text-brand'
                            : 'border-transparent text-text-tertiary hover:border-border-strong hover:text-text-secondary'
                    ]"
                >
                    Regional Settings
                </button>
                <button
                    @click="activeTab = 'approvals'"
                    :class="[
                        'border-b-2 px-1 py-3 text-sm font-medium transition-colors',
                        activeTab === 'approvals'
                            ? 'border-brand text-brand'
                            : 'border-transparent text-text-tertiary hover:border-border-strong hover:text-text-secondary'
                    ]"
                >
                    {{ t('approvals.settings.tab') }}
                </button>
                <button
                    v-if="isAdmin"
                    @click="activeTab = 'users'"
                    :class="[
                        'border-b-2 px-1 py-3 text-sm font-medium transition-colors',
                        activeTab === 'users'
                            ? 'border-brand text-brand'
                            : 'border-transparent text-text-tertiary hover:border-border-strong hover:text-text-secondary'
                    ]"
                >
                    User Management
                </button>
                <button
                    @click="activeTab = 'portal'"
                    :class="[
                        'border-b-2 px-1 py-3 text-sm font-medium transition-colors',
                        activeTab === 'portal'
                            ? 'border-brand text-brand'
                            : 'border-transparent text-text-tertiary hover:border-border-strong hover:text-text-secondary'
                    ]"
                >
                    {{ t('portal.settings.tab') }}
                </button>
            </nav>
        </div>

        <!-- General Information Tab -->
        <div v-show="activeTab === 'general'" class="mt-6">
            <Card :padded="false">
                <form @submit.prevent="submitGeneral">
                    <div class="px-5 pt-5">
                        <h3 class="text-sm font-semibold text-text-primary">Organization Information</h3>
                    </div>
                    <div class="p-5">
                        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                            <div>
                                <label for="name" :class="fieldLabel">Organization Name</label>
                                <input
                                    id="name"
                                    v-model="generalForm.name"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                />
                                <p v-if="generalForm.errors.name" :class="fieldError">{{ generalForm.errors.name }}</p>
                            </div>
                            <div>
                                <label for="email" :class="fieldLabel">Email</label>
                                <input
                                    id="email"
                                    v-model="generalForm.email"
                                    type="email"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                />
                                <p v-if="generalForm.errors.email" :class="fieldError">{{ generalForm.errors.email }}</p>
                            </div>
                            <div>
                                <label for="phone" :class="fieldLabel">Phone</label>
                                <input
                                    id="phone"
                                    v-model="generalForm.phone"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                />
                                <p v-if="generalForm.errors.phone" :class="fieldError">{{ generalForm.errors.phone }}</p>
                            </div>
                            <div>
                                <label for="address" :class="fieldLabel">Address</label>
                                <input
                                    id="address"
                                    v-model="generalForm.address"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                />
                                <p v-if="generalForm.errors.address" :class="fieldError">{{ generalForm.errors.address }}</p>
                            </div>
                            <div>
                                <label for="city" :class="fieldLabel">City</label>
                                <input
                                    id="city"
                                    v-model="generalForm.city"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                />
                                <p v-if="generalForm.errors.city" :class="fieldError">{{ generalForm.errors.city }}</p>
                            </div>
                            <div>
                                <label for="state" :class="fieldLabel">State/Province</label>
                                <input
                                    id="state"
                                    v-model="generalForm.state"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                />
                                <p v-if="generalForm.errors.state" :class="fieldError">{{ generalForm.errors.state }}</p>
                            </div>
                            <div>
                                <label for="zip" :class="fieldLabel">ZIP/Postal Code</label>
                                <input
                                    id="zip"
                                    v-model="generalForm.zip"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                />
                                <p v-if="generalForm.errors.zip" :class="fieldError">{{ generalForm.errors.zip }}</p>
                            </div>
                            <div>
                                <label for="country" :class="fieldLabel">Country</label>
                                <input
                                    id="country"
                                    v-model="generalForm.country"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                />
                                <p v-if="generalForm.errors.country" :class="fieldError">{{ generalForm.errors.country }}</p>
                            </div>
                        </div>

                        <div v-if="isAdmin" class="mt-6 flex justify-end">
                            <Button type="submit" variant="default" :loading="generalForm.processing" :disabled="generalForm.processing">
                                Save Changes
                            </Button>
                        </div>
                    </div>
                </form>
            </Card>
        </div>

        <!-- Regional Settings Tab -->
        <div v-show="activeTab === 'regional'" class="mt-6">
            <Card :padded="false">
                <form @submit.prevent="submitRegional">
                    <div class="px-5 pt-5">
                        <h3 class="text-sm font-semibold text-text-primary">Regional Settings</h3>
                    </div>
                    <div class="p-5">
                        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                            <div>
                                <label for="currency" :class="fieldLabel">Currency</label>
                                <input
                                    id="currency"
                                    v-model="regionalForm.currency"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                    placeholder="USD"
                                />
                                <p v-if="regionalForm.errors.currency" :class="fieldError">{{ regionalForm.errors.currency }}</p>
                            </div>
                            <div>
                                <label for="timezone" :class="fieldLabel">Timezone</label>
                                <input
                                    id="timezone"
                                    v-model="regionalForm.timezone"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                    placeholder="UTC"
                                />
                                <p v-if="regionalForm.errors.timezone" :class="fieldError">{{ regionalForm.errors.timezone }}</p>
                            </div>
                            <div>
                                <label for="date_format" :class="fieldLabel">Date Format</label>
                                <input
                                    id="date_format"
                                    v-model="regionalForm.date_format"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                    placeholder="Y-m-d"
                                />
                                <p v-if="regionalForm.errors.date_format" :class="fieldError">{{ regionalForm.errors.date_format }}</p>
                            </div>
                            <div>
                                <label for="time_format" :class="fieldLabel">Time Format</label>
                                <input
                                    id="time_format"
                                    v-model="regionalForm.time_format"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                    placeholder="H:i"
                                />
                                <p v-if="regionalForm.errors.time_format" :class="fieldError">{{ regionalForm.errors.time_format }}</p>
                            </div>
                        </div>

                        <div v-if="isAdmin" class="mt-6 flex justify-end">
                            <Button type="submit" variant="default" :loading="regionalForm.processing" :disabled="regionalForm.processing">
                                Save Changes
                            </Button>
                        </div>
                    </div>
                </form>
            </Card>
        </div>

        <!-- Approvals Tab -->
        <div v-show="activeTab === 'approvals'" class="mt-6">
            <Card :padded="false">
                <form @submit.prevent="submitApprovals">
                    <div class="px-5 pt-5">
                        <h3 class="text-sm font-semibold text-text-primary">{{ t('approvals.settings.title') }}</h3>
                        <p class="mt-1 text-sm text-text-secondary">{{ t('approvals.settings.description') }}</p>
                    </div>
                    <div class="space-y-6 p-5">
                        <!-- Purchase orders -->
                        <fieldset class="space-y-2">
                            <label :class="toggleRow">
                                <input v-model="approvalsForm.purchase_orders_enabled" type="checkbox" :class="toggleBox" :disabled="!canManageOrganization" />
                                <span>
                                    <span class="block text-sm font-medium text-text-primary">{{ t('approvals.settings.purchaseOrders') }}</span>
                                    <span class="block text-xs text-text-tertiary">{{ t('approvals.settings.purchaseOrdersHelp') }}</span>
                                </span>
                            </label>
                            <div v-if="approvalsForm.purchase_orders_enabled" class="pl-8 sm:max-w-xs">
                                <label for="po_threshold" :class="fieldLabel">{{ t('approvals.settings.poThreshold') }}</label>
                                <input id="po_threshold" v-model="approvalsForm.purchase_orders_threshold" type="number" min="0" step="0.01" inputmode="decimal" :class="fieldInput" :disabled="!canManageOrganization" :placeholder="t('approvals.settings.everyOne')" />
                                <p v-if="approvalsForm.errors.purchase_orders_threshold" :class="fieldError">{{ approvalsForm.errors.purchase_orders_threshold }}</p>
                            </div>
                        </fieldset>

                        <!-- Stock adjustments -->
                        <fieldset class="space-y-2">
                            <label :class="toggleRow">
                                <input v-model="approvalsForm.stock_adjustments_enabled" type="checkbox" :class="toggleBox" :disabled="!canManageOrganization" />
                                <span>
                                    <span class="block text-sm font-medium text-text-primary">{{ t('approvals.settings.stockAdjustments') }}</span>
                                    <span class="block text-xs text-text-tertiary">{{ t('approvals.settings.stockAdjustmentsHelp') }}</span>
                                </span>
                            </label>
                            <div v-if="approvalsForm.stock_adjustments_enabled" class="grid grid-cols-1 gap-4 pl-8 sm:grid-cols-2">
                                <div>
                                    <label for="adj_qty_threshold" :class="fieldLabel">{{ t('approvals.settings.quantityThreshold') }}</label>
                                    <input id="adj_qty_threshold" v-model="approvalsForm.stock_adjustments_quantity_threshold" type="number" min="0" step="1" inputmode="numeric" :class="fieldInput" :disabled="!canManageOrganization" :placeholder="t('approvals.settings.noLimit')" />
                                    <p v-if="approvalsForm.errors.stock_adjustments_quantity_threshold" :class="fieldError">{{ approvalsForm.errors.stock_adjustments_quantity_threshold }}</p>
                                </div>
                                <div>
                                    <label for="adj_value_threshold" :class="fieldLabel">{{ t('approvals.settings.valueThreshold') }}</label>
                                    <input id="adj_value_threshold" v-model="approvalsForm.stock_adjustments_value_threshold" type="number" min="0" step="0.01" inputmode="decimal" :class="fieldInput" :disabled="!canManageOrganization" :placeholder="t('approvals.settings.noLimit')" />
                                    <p v-if="approvalsForm.errors.stock_adjustments_value_threshold" :class="fieldError">{{ approvalsForm.errors.stock_adjustments_value_threshold }}</p>
                                </div>
                                <p class="text-xs text-text-tertiary sm:col-span-2">{{ t('approvals.settings.thresholdHelp') }}</p>
                            </div>
                        </fieldset>

                        <!-- Stock transfers -->
                        <label :class="toggleRow">
                            <input v-model="approvalsForm.stock_transfers_enabled" type="checkbox" :class="toggleBox" :disabled="!canManageOrganization" />
                            <span>
                                <span class="block text-sm font-medium text-text-primary">{{ t('approvals.settings.stockTransfers') }}</span>
                                <span class="block text-xs text-text-tertiary">{{ t('approvals.settings.stockTransfersHelp') }}</span>
                            </span>
                        </label>

                        <!-- Self-approval -->
                        <label :class="[toggleRow, 'border-t border-border-subtle pt-4']">
                            <input v-model="approvalsForm.admins_can_self_approve" type="checkbox" :class="toggleBox" :disabled="!canManageOrganization" />
                            <span>
                                <span class="block text-sm font-medium text-text-primary">{{ t('approvals.settings.adminsSelfApprove') }}</span>
                                <span class="block text-xs text-text-tertiary">{{ t('approvals.settings.adminsSelfApproveHelp') }}</span>
                            </span>
                        </label>

                        <div v-if="canManageOrganization" class="flex justify-end">
                            <Button type="submit" variant="default" :loading="approvalsForm.processing" :disabled="approvalsForm.processing">
                                {{ t('common.saveChanges') }}
                            </Button>
                        </div>
                    </div>
                </form>
            </Card>
        </div>

        <!-- Customer Portal Tab -->
        <div v-show="activeTab === 'portal'" class="mt-6">
            <Card :padded="false">
                <div class="flex items-start justify-between gap-4 px-5 pt-5">
                    <div>
                        <h3 class="text-sm font-semibold text-text-primary">{{ t('portal.settings.title') }}</h3>
                        <p class="mt-0.5 text-sm text-text-secondary">{{ t('portal.settings.description') }}</p>
                    </div>
                    <Badge :variant="portal.enabled ? 'success' : 'neutral'" size="sm" dot>
                        {{ portal.enabled ? t('common.active') : t('common.inactive') }}
                    </Badge>
                </div>
                <div class="space-y-4 p-5">
                    <p class="text-sm text-text-primary">
                        {{ portal.enabled ? t('portal.settings.enabled') : t('portal.settings.disabled') }}
                    </p>
                    <div v-if="portal.login_url" class="rounded-md border border-border-subtle bg-surface-overlay px-3 py-2">
                        <p class="text-xs text-text-tertiary">{{ t('portal.settings.loginUrl') }}</p>
                        <a
                            v-if="portal.enabled"
                            :href="portal.login_url"
                            target="_blank"
                            rel="noopener"
                            class="mt-0.5 inline-flex items-center gap-1 break-all text-sm text-brand hover:underline"
                        >
                            {{ portal.login_url }}
                            <ExternalLink :size="12" class="shrink-0" />
                        </a>
                        <p v-else class="mt-0.5 break-all text-sm text-text-secondary">{{ portal.login_url }}</p>
                    </div>
                    <p class="text-xs text-text-tertiary">{{ t('portal.settings.hint') }}</p>

                    <div v-if="isAdmin" class="flex justify-end">
                        <Button
                            :variant="portal.enabled ? 'secondary' : 'default'"
                            :loading="portalSaving"
                            :disabled="portalSaving"
                            @click="setPortalEnabled(!portal.enabled)"
                        >
                            {{ portal.enabled ? t('portal.settings.turnOff') : t('portal.settings.turnOn') }}
                        </Button>
                    </div>
                </div>
            </Card>
        </div>

        <!-- User Management Tab -->
        <div v-show="activeTab === 'users' && isAdmin" class="mt-6">
            <Card>
                <div class="flex flex-col items-center gap-3 py-12 text-center">
                    <Users :size="40" class="text-text-tertiary" />
                    <h3 class="text-sm font-semibold text-text-primary">User Management</h3>
                    <p class="text-sm text-text-secondary">
                        User management functionality is available in the Users section.
                    </p>
                    <Button variant="default" as="Link" :href="route('users.index')">
                        Go to User Management
                    </Button>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
