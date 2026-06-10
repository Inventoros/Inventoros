<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PluginSlot from '@/Components/PluginSlot.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Pencil, ArrowLeft, Trash2, PackageOpen } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    supplier: Object,
    pluginComponents: Object,
});

const deleteSupplier = () => {
    if (confirm(t('products.confirmDelete', { name: props.supplier.name }))) {
        router.delete(route('suppliers.destroy', props.supplier.id));
    }
};

const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: props.supplier.currency || 'USD',
    }).format(value || 0);
};

const thClass = 'px-4 py-2.5 text-left text-xs font-medium text-text-secondary';
</script>

<template>
    <Head :title="supplier.name" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('suppliers.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('suppliers.index')" class="text-text-tertiary hover:text-text-primary">{{ t('suppliers.title') }}</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ supplier.name }}</span>
            </div>
        </template>

        <PageHeader :title="supplier.name" :description="supplier.code ? `Code: ${supplier.code}` : 'Supplier details'">
            <template #actions>
                <Badge :variant="supplier.is_active ? 'success' : 'neutral'" size="sm" dot>
                    {{ supplier.is_active ? t('common.active') : t('common.inactive') }}
                </Badge>
                <Button variant="default" size="sm" as="Link" :href="route('suppliers.edit', supplier.id)">
                    <Pencil :size="14" />
                    {{ t('common.edit') }}
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('suppliers.index')">
                    <ArrowLeft :size="14" />
                    {{ t('common.back') }}
                </Button>
            </template>
        </PageHeader>

        <!-- Plugin Slot: Header -->
        <PluginSlot slot="header" :components="pluginComponents?.header" />

        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <!-- Main Info -->
            <div class="space-y-4 lg:col-span-2">
                <!-- Contact Information -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Contact Information</h3></div>
                    <div class="p-5">
                        <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                            <div>
                                <dt class="text-xs text-text-tertiary">Contact Person</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ supplier.contact_name || '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">Email</dt>
                                <dd class="mt-1 text-sm text-text-primary">
                                    <a v-if="supplier.email" :href="`mailto:${supplier.email}`" class="text-brand hover:underline">
                                        {{ supplier.email }}
                                    </a>
                                    <span v-else>-</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">Phone</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ supplier.phone || '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">Website</dt>
                                <dd class="mt-1 text-sm text-text-primary">
                                    <a v-if="supplier.website" :href="supplier.website" target="_blank" class="text-brand hover:underline">
                                        {{ supplier.website }}
                                    </a>
                                    <span v-else>-</span>
                                </dd>
                            </div>
                        </dl>
                    </div>
                </Card>

                <!-- Address -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Address</h3></div>
                    <div class="p-5">
                        <p class="text-sm text-text-primary">
                            <template v-if="supplier.address || supplier.city || supplier.state || supplier.zip_code || supplier.country">
                                <span v-if="supplier.address">{{ supplier.address }}<br></span>
                                <span v-if="supplier.city">{{ supplier.city }}, </span>
                                <span v-if="supplier.state">{{ supplier.state }} </span>
                                <span v-if="supplier.zip_code">{{ supplier.zip_code }}<br></span>
                                <span v-if="supplier.country">{{ supplier.country }}</span>
                            </template>
                            <template v-else>
                                <span class="text-text-tertiary">No address provided</span>
                            </template>
                        </p>
                    </div>
                </Card>

                <!-- Products -->
                <Card :padded="false">
                    <div class="flex items-center justify-between px-5 pt-5">
                        <h3 class="text-sm font-semibold text-text-primary">Products</h3>
                        <Badge variant="brand" size="sm">{{ supplier.products?.length || 0 }}</Badge>
                    </div>
                    <div class="p-5">
                        <div v-if="supplier.products?.length > 0" class="w-full overflow-x-auto rounded-lg border border-border-subtle">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-border-subtle">
                                        <th :class="thClass">Product</th>
                                        <th :class="thClass">SKU</th>
                                        <th :class="thClass">Supplier SKU</th>
                                        <th :class="[thClass, 'text-right']">Cost Price</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="product in supplier.products" :key="product.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                                        <td class="px-4 py-3">
                                            <Link :href="route('products.show', product.id)" class="text-sm font-medium text-brand hover:underline">
                                                {{ product.name }}
                                            </Link>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-text-tertiary">{{ product.sku }}</td>
                                        <td class="px-4 py-3 text-sm text-text-tertiary">{{ product.pivot?.supplier_sku || '-' }}</td>
                                        <td class="px-4 py-3 text-right text-sm tabular-nums text-text-secondary">{{ formatCurrency(product.pivot?.cost_price) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="flex flex-col items-center gap-2 py-8 text-center">
                            <PackageOpen :size="22" class="text-text-tertiary" />
                            <p class="text-sm text-text-tertiary">No products linked to this supplier</p>
                        </div>
                    </div>
                </Card>
            </div>

            <!-- Sidebar -->
            <div class="space-y-4">
                <!-- Business Details Card -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Business Details</h3></div>
                    <div class="p-5">
                        <dl class="space-y-3">
                            <div>
                                <dt class="text-xs text-text-tertiary">Supplier Code</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ supplier.code || '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">Payment Terms</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ supplier.payment_terms || '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-text-tertiary">Currency</dt>
                                <dd class="mt-1 text-sm text-text-primary">{{ supplier.currency || 'USD' }}</dd>
                            </div>
                        </dl>
                    </div>
                </Card>

                <!-- Notes Card -->
                <Card v-if="supplier.notes" :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Notes</h3></div>
                    <div class="p-5">
                        <p class="whitespace-pre-wrap text-sm text-text-secondary">{{ supplier.notes }}</p>
                    </div>
                </Card>

                <!-- Danger Zone -->
                <Card :padded="false">
                    <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Danger Zone</h3></div>
                    <div class="p-5">
                        <Button variant="danger" class="w-full" @click="deleteSupplier">
                            <Trash2 :size="14" />
                            Delete Supplier
                        </Button>
                        <p class="mt-2 text-xs text-text-tertiary">
                            This permanently removes the supplier and cannot be undone.
                        </p>
                    </div>
                </Card>
            </div>
        </div>

        <!-- Plugin Slot: Footer -->
        <PluginSlot slot="footer" :components="pluginComponents?.footer" />
    </AppLayout>
</template>

