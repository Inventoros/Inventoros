<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Lock, Search, Unlock, X } from 'lucide-vue-next';
import { displayDate } from '@/lib/dates';

const { t } = useI18n();

const props = defineProps({
    reservations: Object,
    filters: Object,
    product: Object,
    owners: Array,
    canRelease: Boolean,
});

const search = ref(props.filters?.search || '');
const status = ref(props.filters?.status || 'active');
const owner = ref(props.filters?.owner || '');

const applyFilters = () => {
    router.get(route('reservations.index'), {
        search: search.value || undefined,
        status: status.value,
        owner: owner.value || undefined,
        product_id: props.filters?.product_id || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const clearProduct = () => {
    router.get(route('reservations.index'), { status: status.value, owner: owner.value || undefined });
};

const statusVariant = (value) =>
    ({ active: 'brand', expired: 'warning', consumed: 'success', released: 'neutral' }[value] || 'neutral');

const statusLabel = (reservation) => {
    if (reservation.status === 'released' && reservation.release_reason) {
        return t(`reservations.reasons.${reservation.release_reason}`);
    }
    return t(`reservations.statuses.${reservation.status}`);
};

const heldIn = (reservation) => {
    if (reservation.serial_number) return t('reservations.serial', { number: reservation.serial_number });
    if (reservation.batch_number) return t('reservations.batch', { number: reservation.batch_number });
    if (reservation.location) return reservation.location.name;
    return t('reservations.productTotal');
};

const release = (reservation) => {
    if (!confirm(t('reservations.confirmRelease', { reference: reservation.owner_reference, owner: reservation.owner_type }))) {
        return;
    }
    router.post(route('reservations.release', reservation.id), {}, { preserveScroll: true });
};

const selectClass =
    'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary ds-focus-ring';
const thClass =
    'px-4 py-2.5 text-left text-xs font-medium text-text-secondary';
</script>

<template>
    <Head :title="t('reservations.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-text-tertiary">{{ t('nav.sections.stock') }}</span>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('nav.reservations') }}</span>
            </div>
        </template>

        <PageHeader :title="t('reservations.title')" :description="t('reservations.subtitle')" />

        <div v-if="product" class="mt-4 flex items-center gap-2 text-sm text-text-secondary">
            <span>{{ t('reservations.forProduct', { product: product.name }) }}</span>
            <button type="button" class="inline-flex items-center gap-1 text-brand hover:underline" @click="clearProduct">
                <X :size="14" />
                {{ t('reservations.allProducts') }}
            </button>
        </div>

        <Card class="mt-6">
            <form @submit.prevent="applyFilters" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="sm:col-span-2">
                        <label for="search" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('common.search') }}</label>
                        <div class="relative">
                            <Search :size="15" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-tertiary" />
                            <input
                                id="search"
                                v-model="search"
                                type="text"
                                :placeholder="t('reservations.searchPlaceholder')"
                                class="h-9 w-full rounded-md border border-border-subtle bg-surface-canvas pl-9 pr-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                            />
                        </div>
                    </div>
                    <div>
                        <label for="status" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('common.status') }}</label>
                        <select id="status" v-model="status" :class="selectClass" @change="applyFilters">
                            <option value="active">{{ t('reservations.statuses.active') }}</option>
                            <option value="expired">{{ t('reservations.statuses.expired') }}</option>
                            <option value="consumed">{{ t('reservations.statuses.consumed') }}</option>
                            <option value="released">{{ t('reservations.statuses.released') }}</option>
                            <option value="all">{{ t('reservations.statuses.all') }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="owner" class="mb-1 block text-xs font-medium text-text-secondary">{{ t('reservations.owner') }}</label>
                        <select id="owner" v-model="owner" :class="selectClass" @change="applyFilters">
                            <option value="">{{ t('reservations.allOwners') }}</option>
                            <option v-for="name in owners" :key="name" :value="name">{{ name }}</option>
                        </select>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button type="submit" variant="default" size="sm">
                        <Search :size="14" />
                        {{ t('reservations.filter') }}
                    </Button>
                </div>
            </form>
        </Card>

        <div class="mt-4 w-full overflow-x-auto rounded-lg border border-border-subtle bg-surface-raised">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border-subtle">
                        <th :class="thClass">{{ t('reservations.product') }}</th>
                        <th :class="[thClass, 'text-right']">{{ t('reservations.quantity') }}</th>
                        <th :class="thClass">{{ t('reservations.heldIn') }}</th>
                        <th :class="thClass">{{ t('reservations.owner') }}</th>
                        <th :class="thClass">{{ t('reservations.reference') }}</th>
                        <th :class="thClass">{{ t('reservations.expires') }}</th>
                        <th :class="thClass">{{ t('common.status') }}</th>
                        <th v-if="canRelease" :class="[thClass, 'text-right']">{{ t('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!reservations.data?.length">
                        <td :colspan="canRelease ? 8 : 7" class="px-4 py-12 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <Lock :size="22" class="text-text-tertiary" />
                                <p class="text-sm text-text-tertiary">{{ t('reservations.empty') }}</p>
                            </div>
                        </td>
                    </tr>
                    <tr v-for="reservation in reservations.data" :key="reservation.id" class="border-b border-border-subtle transition-colors last:border-b-0 hover:bg-surface-overlay">
                        <td class="px-4 py-3">
                            <Link v-if="reservation.product" :href="route('products.show', reservation.product.id)" class="font-medium text-text-primary hover:text-brand">
                                {{ reservation.product.name }}
                            </Link>
                            <p class="text-xs text-text-tertiary">
                                {{ reservation.variant ? (reservation.variant.title || reservation.variant.sku) : reservation.product?.sku }}
                            </p>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-text-primary">{{ reservation.quantity }}</td>
                        <td class="px-4 py-3 text-text-secondary">{{ heldIn(reservation) }}</td>
                        <td class="px-4 py-3"><span class="font-mono text-xs text-text-secondary">{{ reservation.owner_type }}</span></td>
                        <td class="px-4 py-3"><span class="font-mono text-xs text-text-primary">{{ reservation.owner_reference }}</span></td>
                        <td class="px-4 py-3 text-text-secondary">{{ reservation.expires_at ? displayDate(reservation.expires_at) : t('reservations.noExpiry') }}</td>
                        <td class="px-4 py-3">
                            <Badge :variant="statusVariant(reservation.status)" size="sm" dot>{{ statusLabel(reservation) }}</Badge>
                        </td>
                        <td v-if="canRelease" class="px-4 py-3">
                            <div class="flex items-center justify-end">
                                <Button v-if="reservation.status === 'active'" variant="secondary" size="sm" @click="release(reservation)">
                                    <Unlock :size="14" />
                                    {{ t('reservations.release') }}
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="reservations.links && reservations.links.length > 3" class="mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row">
            <p class="text-xs text-text-tertiary">
                {{ t('reservations.showing', { from: reservations.from, to: reservations.to, total: reservations.total }) }}
            </p>
            <nav class="inline-flex items-center gap-1">
                <template v-for="link in reservations.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        :class="[
                            'inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2.5 text-xs font-medium transition-colors',
                            link.active
                                ? 'border-brand bg-brand text-brand-foreground'
                                : 'border-border-subtle bg-surface-canvas text-text-secondary hover:bg-surface-overlay',
                        ]"
                        v-html="link.label"
                    />
                    <span v-else class="inline-flex h-8 min-w-8 cursor-not-allowed items-center justify-center rounded-md border border-border-subtle px-2.5 text-xs text-text-tertiary opacity-50" v-html="link.label" />
                </template>
            </nav>
        </div>
    </AppLayout>
</template>
