<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Boxes, ArrowUp, ArrowDown, Clock, CheckCircle2 } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    adjustment: Object,
});

const typeVariant = (type) =>
    ({
        manual: 'info',
        recount: 'brand',
        damage: 'danger',
        loss: 'warning',
        return: 'success',
        correction: 'warning',
        order: 'neutral',
    }[type] || 'info');

const typeLabels = {
    'manual': 'Manual Adjustment',
    'recount': 'Stock Recount',
    'damage': 'Damage',
    'loss': 'Loss',
    'return': 'Return',
    'correction': 'Correction',
    'order': 'Order',
};

const isIncrease = computed(() => props.adjustment.adjustment_quantity > 0);
const isDecrease = computed(() => props.adjustment.adjustment_quantity < 0);

const formatDate = (date) =>
    new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });

const formatTime = (date) =>
    new Date(date).toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit',
    });
</script>

<template>
    <Head title="Stock Adjustment Details" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('stock-adjustments.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('stock-adjustments.index')" class="text-text-tertiary hover:text-text-primary">Stock Adjustments</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">#{{ adjustment.id }}</span>
            </div>
        </template>

        <PageHeader
            title="Stock Adjustment Details"
            :description="`Adjustment #${adjustment.id}`"
        >
            <template #actions>
                <Badge :variant="typeVariant(adjustment.type)" size="sm">{{ typeLabels[adjustment.type] || adjustment.type }}</Badge>
                <Button variant="secondary" size="sm" as="Link" :href="route('stock-adjustments.index')">
                    <ArrowLeft :size="14" />
                    Back to List
                </Button>
            </template>
        </PageHeader>

        <!-- Summary Tiles -->
        <section class="mt-6 grid grid-cols-1 gap-3 md:grid-cols-3">
            <StatTile
                label="Stock Before"
                :value="adjustment.quantity_before"
                hint="units"
                icon-tone="info"
            >
                <template #icon><Boxes :size="18" /></template>
            </StatTile>
            <StatTile
                label="Adjustment"
                :value="`${adjustment.adjustment_quantity > 0 ? '+' : ''}${adjustment.adjustment_quantity}`"
                hint="units"
                :icon-tone="isIncrease ? 'success' : isDecrease ? 'warning' : 'brand'"
            >
                <template #icon>
                    <ArrowUp v-if="isIncrease" :size="18" />
                    <ArrowDown v-else-if="isDecrease" :size="18" />
                    <Boxes v-else :size="18" />
                </template>
            </StatTile>
            <StatTile
                label="Stock After"
                :value="adjustment.quantity_after"
                hint="units"
                icon-tone="brand"
            >
                <template #icon><Boxes :size="18" /></template>
            </StatTile>
        </section>

        <!-- Adjustment Details -->
        <Card :padded="false" class="mt-4">
            <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Adjustment Information</h3></div>
            <div class="p-5">
                <dl class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div>
                        <dt class="mb-1 text-xs text-text-tertiary">Product</dt>
                        <dd>
                            <Link
                                :href="route('products.show', adjustment.product.id)"
                                class="text-sm font-medium text-brand hover:underline"
                            >
                                {{ adjustment.product.name }}
                            </Link>
                            <p class="mt-1 text-xs text-text-tertiary">SKU: {{ adjustment.product.sku }}</p>
                        </dd>
                    </div>

                    <div>
                        <dt class="mb-1 text-xs text-text-tertiary">Type</dt>
                        <dd>
                            <Badge :variant="typeVariant(adjustment.type)" size="sm">
                                {{ typeLabels[adjustment.type] || adjustment.type }}
                            </Badge>
                        </dd>
                    </div>

                    <div>
                        <dt class="mb-1 text-xs text-text-tertiary">Adjusted By</dt>
                        <dd class="text-sm text-text-primary">{{ adjustment.user?.name || 'System' }}</dd>
                        <p v-if="adjustment.user?.email" class="mt-1 text-xs text-text-tertiary">
                            {{ adjustment.user.email }}
                        </p>
                    </div>

                    <div>
                        <dt class="mb-1 text-xs text-text-tertiary">Date & Time</dt>
                        <dd class="text-sm text-text-primary">{{ formatDate(adjustment.created_at) }}</dd>
                        <p class="mt-1 text-xs text-text-tertiary">{{ formatTime(adjustment.created_at) }}</p>
                    </div>

                    <div class="md:col-span-2">
                        <dt class="mb-1 text-xs text-text-tertiary">Reason</dt>
                        <dd class="text-sm text-text-primary">{{ adjustment.reason }}</dd>
                    </div>

                    <div v-if="adjustment.notes" class="md:col-span-2">
                        <dt class="mb-1 text-xs text-text-tertiary">Notes</dt>
                        <dd class="whitespace-pre-line text-sm text-text-primary">{{ adjustment.notes }}</dd>
                    </div>

                    <div v-if="adjustment.reference_type" class="md:col-span-2">
                        <dt class="mb-1 text-xs text-text-tertiary">Reference</dt>
                        <dd class="rounded-lg border border-border-subtle bg-surface-canvas p-3">
                            <p class="text-sm text-text-primary">
                                {{ adjustment.reference_type }} #{{ adjustment.reference_id }}
                            </p>
                        </dd>
                    </div>
                </dl>
            </div>
        </Card>

        <!-- Visual Timeline -->
        <Card :padded="false" class="mt-4">
            <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Stock Change Timeline</h3></div>
            <div class="p-5">
                <div class="relative">
                    <!-- Timeline line -->
                    <div class="absolute left-8 top-8 bottom-8 w-px bg-border-subtle"></div>

                    <!-- Before -->
                    <div class="relative mb-8 flex items-start gap-4">
                        <div class="relative z-10 flex h-16 w-16 flex-shrink-0 items-center justify-center rounded-full bg-status-info-soft">
                            <Clock :size="28" class="text-status-info" />
                        </div>
                        <div class="flex-1 rounded-lg border border-border-subtle bg-surface-canvas p-4">
                            <p class="text-sm font-medium text-text-secondary">Starting Stock</p>
                            <p class="mt-1 text-2xl font-bold tabular-nums text-text-primary">{{ adjustment.quantity_before }} units</p>
                        </div>
                    </div>

                    <!-- Adjustment -->
                    <div class="relative mb-8 flex items-start gap-4">
                        <div
                            class="relative z-10 flex h-16 w-16 flex-shrink-0 items-center justify-center rounded-full"
                            :class="isIncrease ? 'bg-status-success-soft' : 'bg-status-danger-soft'"
                        >
                            <ArrowUp v-if="isIncrease" :size="28" class="text-status-success" />
                            <ArrowDown v-else :size="28" class="text-status-danger" />
                        </div>
                        <div
                            class="flex-1 rounded-lg border p-4"
                            :class="isIncrease ? 'border-status-success/20 bg-status-success-soft' : 'border-status-danger/20 bg-status-danger-soft'"
                        >
                            <p class="text-sm font-medium" :class="isIncrease ? 'text-status-success' : 'text-status-danger'">
                                {{ isIncrease ? 'Stock Increased' : 'Stock Decreased' }}
                            </p>
                            <p class="mt-1 text-2xl font-bold tabular-nums" :class="isIncrease ? 'text-status-success' : 'text-status-danger'">
                                {{ adjustment.adjustment_quantity > 0 ? '+' : '' }}{{ adjustment.adjustment_quantity }} units
                            </p>
                            <p class="mt-2 text-xs" :class="isIncrease ? 'text-status-success' : 'text-status-danger'">
                                {{ adjustment.reason }}
                            </p>
                        </div>
                    </div>

                    <!-- After -->
                    <div class="relative flex items-start gap-4">
                        <div class="relative z-10 flex h-16 w-16 flex-shrink-0 items-center justify-center rounded-full bg-brand-soft">
                            <CheckCircle2 :size="28" class="text-brand" />
                        </div>
                        <div class="flex-1 rounded-lg border border-brand/20 bg-brand-soft p-4">
                            <p class="text-sm font-medium text-brand">Final Stock</p>
                            <p class="mt-1 text-2xl font-bold tabular-nums text-text-primary">{{ adjustment.quantity_after }} units</p>
                        </div>
                    </div>
                </div>
            </div>
        </Card>
    </AppLayout>
</template>

