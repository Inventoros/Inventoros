<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Pencil, ArrowLeft, Trash2, ShoppingCart, Wallet, PackageOpen } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    customer: Object,
});

const orders = computed(() => props.customer.orders || []);

const totalSpend = computed(() =>
    orders.value.reduce((sum, o) => sum + parseFloat(o.total || 0), 0)
);

const formatCurrency = (value) =>
    new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: props.customer.currency || 'USD',
    }).format(value || 0);

const formatDate = (date) => (date ? new Date(date).toLocaleDateString() : '-');

const statusVariant = (status) =>
    ({
        pending: 'warning',
        processing: 'info',
        shipped: 'brand',
        delivered: 'success',
        completed: 'success',
        cancelled: 'danger',
    }[status] || 'neutral');

const deleteCustomer = () => {
    if (confirm(t('products.confirmDelete', { name: props.customer.name }))) {
        router.delete(route('customers.destroy', props.customer.id));
    }
};

const thClass = 'px-4 py-2.5 text-left text-xs font-medium tracking-tight text-text-secondary';
</script>

<template>
    <Head :title="customer.name" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('customers.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('customers.index')" class="text-text-tertiary hover:text-text-primary">{{ t('customers.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ customer.name }}</span>
            </div>
        </template>

        <PageHeader
            :title="customer.name"
            :description="customer.code ? `Code: ${customer.code}` : customer.company_name || null"
        >
            <template #actions>
                <Badge :variant="customer.is_active ? 'success' : 'neutral'" size="sm" dot>
                    {{ customer.is_active ? t('common.active') : t('common.inactive') }}
                </Badge>
                <Button variant="default" size="sm" as="Link" :href="route('customers.edit', customer.id)">
                    <Pencil :size="14" />
                    {{ t('common.edit') }}
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('customers.index')">
                    <ArrowLeft :size="14" />
                    {{ t('customers.create.backToCustomers') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Key metrics -->
        <section class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <StatTile
                :label="t('customers.show.recentOrders')"
                :value="orders.length"
                icon-tone="brand"
            >
                <template #icon><ShoppingCart :size="18" /></template>
            </StatTile>
            <StatTile
                :label="t('common.total')"
                :value="formatCurrency(totalSpend)"
                :hint="customer.currency || 'USD'"
                icon-tone="success"
            >
                <template #icon><Wallet :size="18" /></template>
            </StatTile>
        </section>

        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <!-- Main column -->
            <div class="space-y-4 lg:col-span-2">
                <!-- Basic Information -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('customers.show.basicInfo') }}</h3></div>
                    <div class="p-5">
                        <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('customers.show.customerName') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ customer.name }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('customers.show.companyName') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ customer.company_name || '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('customers.show.contactPerson') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ customer.contact_name || '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('common.email') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">
                                    <a v-if="customer.email" :href="`mailto:${customer.email}`" class="text-brand hover:underline">
                                        {{ customer.email }}
                                    </a>
                                    <span v-else>-</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('common.phone') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ customer.phone || '-' }}</dd>
                            </div>
                        </dl>
                    </div>
                </Card>

                <!-- Addresses -->
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <!-- Billing Address -->
                    <Card :padded="false">
                        <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('customers.show.billingAddress') }}</h3></div>
                        <div class="p-5">
                            <address class="not-italic text-sm text-text-primary">
                                <template v-if="customer.billing_address || customer.billing_city">
                                    <div v-if="customer.billing_address">{{ customer.billing_address }}</div>
                                    <div v-if="customer.billing_city || customer.billing_state || customer.billing_zip_code">
                                        {{ [customer.billing_city, customer.billing_state, customer.billing_zip_code].filter(Boolean).join(', ') }}
                                    </div>
                                    <div v-if="customer.billing_country">{{ customer.billing_country }}</div>
                                </template>
                                <span v-else class="text-text-tertiary">{{ t('customers.show.noBillingAddress') }}</span>
                            </address>
                        </div>
                    </Card>

                    <!-- Shipping Address -->
                    <Card :padded="false">
                        <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('customers.show.shippingAddress') }}</h3></div>
                        <div class="p-5">
                            <address class="not-italic text-sm text-text-primary">
                                <template v-if="customer.shipping_address || customer.shipping_city">
                                    <div v-if="customer.shipping_address">{{ customer.shipping_address }}</div>
                                    <div v-if="customer.shipping_city || customer.shipping_state || customer.shipping_zip_code">
                                        {{ [customer.shipping_city, customer.shipping_state, customer.shipping_zip_code].filter(Boolean).join(', ') }}
                                    </div>
                                    <div v-if="customer.shipping_country">{{ customer.shipping_country }}</div>
                                </template>
                                <span v-else class="text-text-tertiary">{{ t('customers.show.noShippingAddress') }}</span>
                            </address>
                        </div>
                    </Card>
                </div>

                <!-- Order History -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('customers.show.recentOrders') }}</h3></div>
                    <div class="p-5">
                        <div v-if="orders.length > 0" class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-border-subtle">
                                        <th :class="thClass">{{ t('orders.show.orderNumber2') }}</th>
                                        <th :class="thClass">{{ t('purchaseOrders.orderDate') }}</th>
                                        <th :class="thClass">{{ t('common.status') }}</th>
                                        <th :class="[thClass, 'text-right']">{{ t('common.total') }}</th>
                                        <th :class="[thClass, 'text-right']">{{ t('common.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="order in orders.slice(0, 5)"
                                        :key="order.id"
                                        class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay"
                                    >
                                        <td class="px-4 py-3">
                                            <Link :href="route('orders.show', order.id)" class="font-medium text-brand hover:underline">
                                                {{ order.order_number }}
                                            </Link>
                                        </td>
                                        <td class="px-4 py-3 text-text-tertiary">{{ formatDate(order.created_at) }}</td>
                                        <td class="px-4 py-3">
                                            <Badge v-if="order.status" :variant="statusVariant(order.status)" size="sm" dot class="capitalize">
                                                {{ order.status }}
                                            </Badge>
                                            <span v-else class="text-text-tertiary">-</span>
                                        </td>
                                        <td class="px-4 py-3 text-right font-medium tabular-nums text-text-primary">
                                            {{ order.total != null ? formatCurrency(order.total) : '-' }}
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <Link :href="route('orders.show', order.id)" class="text-sm text-brand hover:underline">
                                                {{ t('common.view') }}
                                            </Link>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="flex flex-col items-center gap-2 py-8 text-center">
                            <PackageOpen :size="22" class="text-text-tertiary" />
                            <p class="text-sm text-text-tertiary">{{ t('customers.show.noOrdersYet') }}</p>
                        </div>
                    </div>
                </Card>
            </div>

            <!-- Sidebar -->
            <div class="space-y-4">
                <!-- Business Details -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('customers.show.businessDetails') }}</h3></div>
                    <div class="p-5">
                        <dl class="space-y-3">
                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('customers.show.taxIdVat') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ customer.tax_id || '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('customers.show.paymentTerms') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ customer.payment_terms || '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('customers.show.creditLimit') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">
                                    {{ customer.credit_limit ? `${customer.currency} ${Number(customer.credit_limit).toLocaleString()}` : '-' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">{{ t('common.currency') }}</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ customer.currency }}</dd>
                            </div>
                            <div v-if="customer.notes" class="border-t border-border-subtle pt-3">
                                <dt class="text-xs text-text-tertiary">{{ t('common.notes') }}</dt>
                                <dd class="mt-1 whitespace-pre-wrap text-sm text-text-primary">{{ customer.notes }}</dd>
                            </div>
                        </dl>
                    </div>
                </Card>

                <!-- Danger Zone -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">{{ t('orders.show.dangerZone') }}</h3></div>
                    <div class="p-5">
                        <Button variant="danger" class="w-full" @click="deleteCustomer">
                            <Trash2 :size="16" />
                            {{ t('customers.show.deleteCustomer') }}
                        </Button>
                    </div>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>

