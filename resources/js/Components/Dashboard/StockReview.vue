<script setup>
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { X } from '@lucide/vue';
import Modal from '@/Components/Modal.vue';
import Button from '@/Components/ui/Button.vue';
import StockPosition from '@/Components/Dashboard/StockPosition.vue';
import { stockLevel } from '@/lib/dashboard';
import { formatNumber } from '@/lib/money';

const props = defineProps({
    product: { type: Object, default: null },
    suggestion: { type: Object, default: null },
    canCreatePo: { type: Boolean, default: false },
    viewHref: { type: String, default: null },
    editHref: { type: String, default: null },
    purchaseOrderHref: { type: String, default: null },
    submitting: { type: Boolean, default: false },
    error: { type: String, default: null },
});
const emit = defineEmits(['close', 'create']);
const { t } = useI18n();
const errorId = useId();
const needsVariants = computed(() => props.suggestion?.can_quick_reorder === false);
const canOrder = computed(() => props.canCreatePo && props.suggestion?.supplier_id && !needsVariants.value);
const canAddSupplier = computed(() => props.canCreatePo && props.suggestion && !props.suggestion.supplier_id && !needsVariants.value && props.editHref);
const canChooseVariants = computed(() => props.canCreatePo && needsVariants.value && props.purchaseOrderHref);
</script>

<template>
    <Modal
        :show="!!product"
        max-width="xl"
        :aria-label="product ? `${t('dashboard.design.reviewStock')}: ${product.name}` : t('dashboard.design.reviewStock')"
        @close="emit('close')"
    >
        <div v-if="product" class="stock-review">
            <header class="stock-review-heading">
                <div>
                    <p v-if="product.sku" class="stock-review-sku">{{ product.sku }}</p>
                    <h2>{{ product.name }}</h2>
                    <p class="stock-review-location">
                        {{ product.category?.name || t('products.show.uncategorized') }}
                        <span v-if="product.location">{{ product.location.name }}</span>
                    </p>
                </div>
                <button type="button" class="stock-review-close ds-focus-ring" :aria-label="t('common.cancel')" @click="emit('close')">
                    <X :size="18" aria-hidden="true" />
                </button>
            </header>

            <div class="stock-review-position">
                <StockPosition :product="product" />
                <div class="stock-review-shortfall">
                    <strong>{{ formatNumber(stockLevel(product).shortfall) }}</strong>
                    <span>{{ t('dashboard.hints.belowMinimum') }}</span>
                </div>
            </div>

            <section v-if="suggestion" class="stock-review-procurement" :aria-label="t('dashboard.reorderSuggestions.title')">
                <h3>{{ t('dashboard.reorderSuggestions.title') }}</h3>
                <dl>
                    <div>
                        <dt>{{ t('purchaseOrders.supplier') }}</dt>
                        <dd>{{ suggestion.supplier || t('quickReorder.noSupplier') }}</dd>
                    </div>
                    <div v-if="suggestion.reorder_point !== null && suggestion.reorder_point !== undefined">
                        <dt>{{ t('dashboard.reorderSuggestions.reorderAt') }}</dt>
                        <dd>{{ formatNumber(suggestion.reorder_point) }}</dd>
                    </div>
                    <div v-if="!needsVariants" class="stock-review-order-quantity">
                        <dt>{{ t('quickReorder.suggestedQty') }}</dt>
                        <dd>{{ formatNumber(suggestion.suggested_quantity) }}</dd>
                    </div>
                </dl>
                <p>{{ needsVariants ? t('quickReorder.variantHint') : t('dashboard.reorderSuggestions.subtitle') }}</p>
            </section>
            <p v-else class="stock-review-help">{{ t('dashboard.design.shortfallHelp') }}</p>

            <p v-if="error" :id="errorId" role="alert" class="stock-review-error">{{ error }}</p>
            <footer class="stock-review-actions">
                <Button v-if="viewHref" as="Link" :href="viewHref" :variant="canOrder || canAddSupplier || canChooseVariants ? 'outline' : 'default'" :disabled="submitting">
                    {{ t('dashboard.design.viewProduct') }}
                </Button>
                <Button v-if="canOrder" :loading="submitting" :disabled="submitting" :aria-describedby="error ? errorId : undefined" @click="emit('create')">
                    {{ t('quickReorder.createPo') }}
                </Button>
                <Button v-else-if="canChooseVariants" as="Link" :href="purchaseOrderHref">
                    {{ t('quickReorder.chooseVariants') }}
                </Button>
                <Button v-else-if="canAddSupplier" as="Link" :href="editHref">
                    {{ t('quickReorder.addSupplier') }}
                </Button>
            </footer>
        </div>
    </Modal>
</template>

<style>
.stock-review {
    padding: 24px;
    color: hsl(var(--text-primary));
    background: hsl(var(--surface-raised));
    font-size: 13px;
}
.stock-review-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
}
.stock-review-heading > div {
    min-width: 0;
}
.stock-review-sku {
    margin-bottom: 6px;
    color: hsl(var(--text-secondary));
    font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
    font-size: 11px;
    overflow-wrap: anywhere;
}
.stock-review h2 {
    font-size: 20px;
    line-height: 28px;
    font-weight: 600;
    text-wrap: balance;
    overflow-wrap: anywhere;
}
.stock-review-location {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 12px;
    margin-top: 6px;
    color: hsl(var(--text-tertiary));
    font-size: 12px;
    overflow-wrap: anywhere;
}
.stock-review-location > span {
    padding-inline-start: 12px;
    border-inline-start: 1px solid hsl(var(--border-strong));
}
.stock-review-close {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 44px;
    height: 44px;
    border: 1px solid hsl(var(--border-subtle));
    border-radius: 6px;
    color: hsl(var(--text-secondary));
}
.stock-review-close:hover {
    background: hsl(var(--surface-overlay));
}
.stock-review-position {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 100px;
    align-items: center;
    gap: 24px;
    margin-block: 24px;
    padding-block: 22px;
    border-block: 1px solid hsl(var(--border-subtle));
}
.stock-review .stock-position-values strong {
    font-size: 30px;
    line-height: 36px;
}
.stock-review-shortfall {
    display: flex;
    flex-direction: column;
    gap: 3px;
    padding-inline-start: 20px;
    border-inline-start: 1px solid hsl(var(--border-subtle));
    color: hsl(var(--status-warning));
    font-size: 11px;
}
.stock-review-shortfall strong {
    font-size: 30px;
    line-height: 36px;
    font-weight: 500;
    font-variant-numeric: tabular-nums;
    overflow-wrap: anywhere;
}
.stock-review-procurement h3 {
    margin-bottom: 14px;
    font-size: 14px;
    font-weight: 600;
}
.stock-review-procurement dl {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto 90px;
    gap: 20px;
}
.stock-review-procurement dt {
    margin-bottom: 6px;
    color: hsl(var(--text-tertiary));
    font-size: 11px;
}
.stock-review-procurement dd {
    font-size: 14px;
    font-weight: 500;
    font-variant-numeric: tabular-nums;
    overflow-wrap: anywhere;
}
.stock-review-order-quantity {
    text-align: end;
}
.stock-review-order-quantity dd {
    color: hsl(var(--accent));
    font-size: 24px;
    line-height: 28px;
}
.stock-review-procurement > p, .stock-review-help {
    margin-top: 16px;
    color: hsl(var(--text-secondary));
    font-size: 12px;
    line-height: 20px;
    text-wrap: pretty;
}
.stock-review-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 24px;
    padding-top: 20px;
    padding-bottom: env(safe-area-inset-bottom);
    border-top: 1px solid hsl(var(--border-subtle));
}
.stock-review-actions > a, .stock-review-actions > button {
    min-height: 44px;
    height: auto;
    padding-block: 10px;
    white-space: normal;
    text-align: center;
}
.stock-review-error {
    margin-top: 16px;
    padding: 12px;
    border: 1px solid hsl(var(--status-danger) / 0.3);
    border-radius: 6px;
    background: hsl(var(--status-danger-soft));
    color: hsl(var(--status-danger));
}
@media (max-width: 40rem) {
    .stock-review {
        padding: 20px;
    }
    .stock-review-position {
        grid-template-columns: minmax(0, 1fr) 76px;
        gap: 14px;
    }
    .stock-review-shortfall {
        padding-inline-start: 12px;
    }
    .stock-review-procurement dl {
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 18px 12px;
    }
    .stock-review-procurement dl > div:first-child {
        grid-column: 1 / -1;
    }
    .stock-review-actions > a, .stock-review-actions > button {
        flex: 1;
    }
}
</style>
