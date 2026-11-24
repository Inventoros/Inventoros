<script setup>
import { computed, nextTick, ref, useId, watch, watchEffect } from 'vue';
import { useI18n } from 'vue-i18n';
import { Search, X } from '@lucide/vue';
import { normalizeSearchText as normalize } from '@/lib/searchText';

defineOptions({ inheritAttrs: false });
const props = defineProps({
    modelValue: { type: [Number, String], default: null },
    products: { type: Array, default: () => [] },
    id: { type: String, default: null },
    required: Boolean,
    disabled: Boolean,
    invalid: Boolean,
    describedby: { type: String, default: null },
    stockLabel: { type: String, default: null },
    stockByProduct: { type: Object, default: null },
});
const emit = defineEmits(['update:modelValue', 'change']);
const { t } = useI18n();
const uid = useId();
const inputId = computed(() => props.id || `product-picker-${uid}`);
const listId = `product-picker-list-${uid}`;
const hintId = `product-picker-hint-${uid}`;
const input = ref(null);
const list = ref(null);
const query = ref('');
const open = ref(false);
const above = ref(false);
const active = ref(-1);
const selected = computed(() => props.products.find(product => String(product.id) === String(props.modelValue)));
const displayName = product => product ? `${product.name}${product.sku ? ' · ' + product.sku : ''}` : '';
const variantsOf = product => product.variants || product.active_variants || [];
// Build the searchable text once per catalog change. Rendering is capped;
// search still considers every product and its variant identifiers.
const catalog = computed(() => props.products.map(product => ({
    product,
    exact: [product.sku, product.barcode, ...variantsOf(product).flatMap(variant => [variant.sku, variant.barcode])].filter(Boolean).map(normalize),
    text: normalize([product.name, product.sku, product.barcode, ...variantsOf(product).flatMap(variant => [variant.title, variant.sku, variant.barcode])].filter(Boolean).join(' ')),
})));
const matches = computed(() => {
    const term = normalize(query.value);
    if (!term) return catalog.value;
    const words = term.split(/\s+/);
    const found = catalog.value.filter(entry => words.every(word => entry.text.includes(word)));
    return [...found.filter(entry => entry.exact.includes(term)), ...found.filter(entry => !entry.exact.includes(term))];
});
const visible = computed(() => matches.value.slice(0, 40));
const optionId = index => `${listId}-${index}`;
const stock = product => props.stockByProduct ? props.stockByProduct[product.id] : product.stock;
const resultContext = product => product.has_variants
    ? t('components.productPicker.chooseVariant')
    : product.is_kit ? [t('products.kit.canMake', { count: stock(product) ?? 0 }), product.limited_by ? t('products.kit.limitedBy', { name: product.limited_by }) : ''].filter(Boolean).join(' · ')
        : props.stockLabel && stock(product) != null ? `${props.stockLabel}: ${stock(product)}` : '';
const variantMatch = product => {
    const term = normalize(query.value);
    if (!term) return '';
    const variant = variantsOf(product).find(variant => [variant.title, variant.sku, variant.barcode].some(value => normalize(value).includes(term)));
    return variant ? [variant.title, variant.sku].filter(Boolean).join(' · ') : '';
};
const update = value => { emit('update:modelValue', value); emit('change', value); };
watch(() => props.modelValue, () => {
    query.value = displayName(selected.value);
    if (selected.value) open.value = false;
}, { immediate: true });
watch(query, () => { active.value = -1; }, { flush: 'sync' });
watchEffect(() => input.value?.setCustomValidity(props.required && !selected.value ? t('orders.create.chooseProduct') : ''));
function show() {
    if (props.disabled) return;
    if (!open.value) query.value = '';
    const rect = input.value.getBoundingClientRect();
    above.value = window.innerHeight - rect.bottom < 260 && rect.top > window.innerHeight - rect.bottom;
    open.value = true;
}
function type(event) {
    const text = event.target.value;
    if (props.modelValue != null && props.modelValue !== '') update(null);
    // Keep typed text after the model watcher clears a previous selection.
    nextTick(() => { query.value = text; });
    open.value = true;
}
function choose(product) {
    update(product.id);
    query.value = displayName(product);
    open.value = false;
    input.value.focus();
}
function close() {
    open.value = false;
    query.value = displayName(selected.value);
}
function keydown(event) {
    if (event.isComposing) return;
    if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
        event.preventDefault();
        if (!open.value) show();
        active.value = visible.value.length ? Math.max(0, Math.min(visible.value.length - 1, active.value + (event.key === 'ArrowDown' ? 1 : -1))) : -1;
        nextTick(() => list.value?.querySelector(`[id="${optionId(active.value)}"]`)?.scrollIntoView({ block: 'nearest' }));
    } else if (event.key === 'Enter' && open.value) {
        event.preventDefault();
        event.stopPropagation();
        if (active.value >= 0) choose(visible.value[active.value].product);
    } else if (event.key === 'Escape' && open.value) {
        event.preventDefault();
        event.stopPropagation();
        close();
    } else if (event.key === 'Tab') close();
}
function blur(event) {
    if (!event.currentTarget.contains(event.relatedTarget)) close();
}
function clear() {
    update(null);
    query.value = '';
    input.value.focus();
    show();
}
</script>

<template>
    <div class="product-picker relative min-w-0" @focusout="blur">
        <div class="relative">
            <Search :size="16" aria-hidden="true" class="pointer-events-none absolute start-3 top-3.5 text-text-tertiary" />
            <input
                :id="inputId" ref="input" v-bind="$attrs" :value="query" type="text" role="combobox" autocomplete="off" spellcheck="false"
                :required="required" :disabled="disabled" :aria-invalid="invalid || undefined" aria-autocomplete="list" aria-haspopup="listbox"
                :aria-expanded="open" :aria-controls="listId" :aria-activedescendant="open && active >= 0 ? optionId(active) : undefined"
                :aria-describedby="[describedby, hintId].filter(Boolean).join(' ')" :placeholder="t('components.productPicker.search')"
                class="h-11 w-full min-w-0 rounded-md border border-border-subtle bg-surface-canvas pe-11 ps-9 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                @focus="show" @click="show" @input="type" @keydown="keydown"
            />
            <button v-if="selected && !disabled" type="button" class="absolute end-0 top-0 flex h-11 w-11 items-center justify-center rounded-md text-text-secondary ds-focus-ring" :aria-label="t('components.productPicker.clear')" @click="clear"><X :size="16" aria-hidden="true" /></button>
        </div>
        <p :id="hintId" class="mt-1 text-xs text-text-tertiary">{{ selected ? resultContext(selected) : t('components.productPicker.search') }}</p>
        <div v-show="open" class="product-picker-popup absolute z-30 w-full rounded-md border border-border-strong bg-surface-raised shadow-lg" :class="above ? 'bottom-full mb-1' : 'top-full mt-1'">
            <ul :id="listId" ref="list" role="listbox" :aria-label="t('common.product')" class="max-h-[min(18rem,40dvh)] overflow-y-auto overscroll-contain p-1">
                <li v-for="({ product }, index) in visible" :id="optionId(index)" :key="product.id" role="option" :aria-selected="String(modelValue) === String(product.id)" class="min-h-11 cursor-pointer rounded px-3 py-2 text-sm text-text-primary" :class="active === index ? 'product-picker-active bg-surface-overlay outline outline-1 -outline-offset-1 outline-border-strong' : 'hover:bg-surface-overlay'" @pointerdown.prevent @click="choose(product)">
                    <span class="block break-words font-medium">{{ product.name }}</span>
                    <span class="block break-words text-xs text-text-secondary">{{ product.sku }}<template v-if="resultContext(product)"> · {{ resultContext(product) }}</template></span>
                    <span v-if="variantMatch(product)" class="block break-words text-xs text-text-secondary">{{ variantMatch(product) }}</span>
                    <slot name="details" :product="product" />
                </li>
            </ul>
            <p role="status" class="border-t border-border-subtle px-3 py-2 text-xs text-text-secondary">{{ !matches.length ? t('common.noResults') : matches.length > 40 ? t('components.productPicker.refine', { shown: visible.length, total: matches.length }) : t('components.productPicker.results', { count: matches.length }) }}</p>
        </div>
    </div>
</template>

<style scoped>
@media (forced-colors: active) { .product-picker-active { outline: 2px solid Highlight; } }
</style>
