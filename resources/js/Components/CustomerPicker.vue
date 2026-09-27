<script setup>
import { ref, watch, onBeforeUnmount } from 'vue';
import axios from 'axios';
import { useI18n } from 'vue-i18n';
import { Search, UserRound, X } from 'lucide-vue-next';

/**
 * Searchable picker over the organization's saved customers for the order
 * forms. Choosing a customer emits `select` with its details so the form can
 * fill name/email/address and set customer_id; `clear` switches back to a
 * one-off customer typed by hand.
 */
const props = defineProps({
    // The currently linked customer ({ id, name, ... }) or null.
    selected: { type: Object, default: null },
});

const emit = defineEmits(['select', 'clear']);

const { t } = useI18n();

const query = ref('');
const results = ref([]);
const open = ref(false);
const loading = ref(false);
const highlighted = ref(-1);
let timer = null;
let requestId = 0;

const search = async (term) => {
    const id = ++requestId;
    loading.value = true;
    try {
        const response = await axios.get(route('orders.customer-lookup'), { params: { q: term } });
        if (id !== requestId) return;
        results.value = response.data.customers ?? [];
        highlighted.value = results.value.length ? 0 : -1;
    } catch {
        if (id === requestId) results.value = [];
    } finally {
        if (id === requestId) loading.value = false;
    }
};

watch(query, (term) => {
    clearTimeout(timer);
    open.value = true;
    timer = setTimeout(() => search(term.trim()), 200);
});

onBeforeUnmount(() => clearTimeout(timer));

const onFocus = () => {
    open.value = true;
    if (!results.value.length) search(query.value.trim());
};

const onBlur = () => {
    // Let a click on a result land before the list closes.
    setTimeout(() => { open.value = false; }, 150);
};

const choose = (customer) => {
    emit('select', customer);
    query.value = '';
    results.value = [];
    open.value = false;
};

const onKeydown = (event) => {
    if (!open.value || !results.value.length) return;
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        highlighted.value = (highlighted.value + 1) % results.value.length;
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        highlighted.value = (highlighted.value - 1 + results.value.length) % results.value.length;
    } else if (event.key === 'Enter' && highlighted.value >= 0) {
        event.preventDefault();
        choose(results.value[highlighted.value]);
    } else if (event.key === 'Escape') {
        open.value = false;
    }
};

const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas pl-9 pr-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
</script>

<template>
    <div>
        <label for="customer_search" class="mb-1 block text-sm font-medium text-text-secondary">{{ t('orders.create.savedCustomer') }}</label>

        <div
            v-if="props.selected"
            class="flex items-center justify-between gap-3 rounded-md border border-border-subtle bg-surface-sunken px-3 py-2"
        >
            <div class="flex min-w-0 items-center gap-2">
                <UserRound :size="16" class="shrink-0 text-text-tertiary" />
                <p class="truncate text-sm text-text-primary">{{ t('orders.create.linkedCustomer', { name: props.selected.name }) }}</p>
            </div>
            <button
                type="button"
                class="inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs text-text-secondary transition-colors hover:bg-surface-overlay hover:text-text-primary ds-focus-ring"
                @click="emit('clear')"
            >
                <X :size="12" />{{ t('orders.create.clearCustomer') }}
            </button>
        </div>

        <div v-else class="relative">
            <Search :size="14" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-tertiary" />
            <input
                id="customer_search"
                v-model="query"
                type="text"
                autocomplete="off"
                role="combobox"
                aria-autocomplete="list"
                aria-controls="customer_search_results"
                :aria-expanded="open"
                :class="fieldInput"
                :placeholder="t('orders.create.searchCustomers')"
                @focus="onFocus"
                @blur="onBlur"
                @keydown="onKeydown"
            />
            <ul
                v-if="open && (results.length || loading || query.trim())"
                id="customer_search_results"
                role="listbox"
                class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-md border border-border-subtle bg-surface-raised py-1 shadow-lg"
            >
                <li v-if="loading && !results.length" class="px-3 py-2 text-sm text-text-tertiary">{{ t('orders.create.searchingCustomers') }}</li>
                <li v-else-if="!results.length" class="px-3 py-2 text-sm text-text-tertiary">{{ t('orders.create.noCustomersFound') }}</li>
                <li
                    v-for="(customer, index) in results"
                    :key="customer.id"
                    role="option"
                    :aria-selected="index === highlighted"
                    class="cursor-pointer px-3 py-2 text-sm"
                    :class="index === highlighted ? 'bg-surface-overlay' : ''"
                    @mousedown.prevent="choose(customer)"
                    @mouseenter="highlighted = index"
                >
                    <p class="font-medium text-text-primary">
                        {{ customer.name }}
                        <span v-if="customer.code" class="font-normal text-text-tertiary">({{ customer.code }})</span>
                    </p>
                    <p v-if="customer.email || customer.company_name" class="text-xs text-text-tertiary">
                        {{ [customer.company_name, customer.email].filter(Boolean).join(' · ') }}
                    </p>
                </li>
            </ul>
        </div>
        <p class="mt-1 text-xs text-text-tertiary">{{ t('orders.create.customerHint') }}</p>
    </div>
</template>
