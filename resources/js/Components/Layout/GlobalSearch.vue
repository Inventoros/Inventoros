<script setup>
import { ref, watch, onMounted, onUnmounted, nextTick, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';

const isOpen = ref(false);
const query = ref('');
const results = ref({
    products: [],
    orders: [],
    customers: [],
    suppliers: [],
    purchase_orders: [],
});
const isLoading = ref(false);
const selectedIndex = ref(-1);
const searchInput = ref(null);
const recentSearches = ref([]);
const showRecent = ref(true);

const RECENT_SEARCHES_KEY = 'global-search-recent';
const MAX_RECENT = 5;

// Flatten results for keyboard navigation
const flatResults = computed(() => {
    const items = [];
    const categories = [
        { key: 'products', label: 'Products', icon: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4' },
        { key: 'orders', label: 'Orders', icon: 'M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z' },
        { key: 'customers', label: 'Customers', icon: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z' },
        { key: 'suppliers', label: 'Suppliers', icon: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4' },
        { key: 'purchase_orders', label: 'Purchase Orders', icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01' },
    ];

    for (const cat of categories) {
        const catResults = results.value[cat.key] || [];
        if (catResults.length > 0) {
            items.push({ type: 'header', label: cat.label, icon: cat.icon });
            for (const item of catResults) {
                items.push({ type: 'result', ...item, categoryIcon: cat.icon });
            }
        }
    }
    return items;
});

const selectableResults = computed(() => {
    return flatResults.value.filter(item => item.type === 'result');
});

const hasResults = computed(() => {
    return selectableResults.value.length > 0;
});

const hasQuery = computed(() => {
    return query.value.trim().length > 0;
});

// Load recent searches from localStorage
function loadRecentSearches() {
    try {
        const stored = localStorage.getItem(RECENT_SEARCHES_KEY);
        if (stored) {
            recentSearches.value = JSON.parse(stored);
        }
    } catch {
        recentSearches.value = [];
    }
}

function saveRecentSearch(item) {
    const existing = recentSearches.value.filter(r => !(r.id === item.id && r.type === item.type));
    recentSearches.value = [
        { id: item.id, title: item.title, subtitle: item.subtitle, url: item.url, type: item.type },
        ...existing,
    ].slice(0, MAX_RECENT);
    localStorage.setItem(RECENT_SEARCHES_KEY, JSON.stringify(recentSearches.value));
}

// Debounced search
let debounceTimer = null;

watch(query, (val) => {
    showRecent.value = false;
    if (debounceTimer) clearTimeout(debounceTimer);

    if (!val.trim()) {
        results.value = { products: [], orders: [], customers: [], suppliers: [], purchase_orders: [] };
        isLoading.value = false;
        showRecent.value = true;
        selectedIndex.value = -1;
        return;
    }

    isLoading.value = true;
    debounceTimer = setTimeout(async () => {
        try {
            const response = await axios.get('/search', { params: { q: val.trim() } });
            results.value = response.data;
            selectedIndex.value = -1;
        } catch {
            results.value = { products: [], orders: [], customers: [], suppliers: [], purchase_orders: [] };
        } finally {
            isLoading.value = false;
        }
    }, 300);
});

function open() {
    isOpen.value = true;
    query.value = '';
    results.value = { products: [], orders: [], customers: [], suppliers: [], purchase_orders: [] };
    selectedIndex.value = -1;
    showRecent.value = true;
    loadRecentSearches();
    nextTick(() => {
        searchInput.value?.focus();
    });
}

function close() {
    isOpen.value = false;
    query.value = '';
    if (debounceTimer) clearTimeout(debounceTimer);
}

function navigateToResult(item) {
    saveRecentSearch(item);
    close();
    router.visit(item.url);
}

function handleKeydown(e) {
    if (!isOpen.value) return;

    const items = selectableResults.value;
    const count = items.length;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        selectedIndex.value = count > 0 ? (selectedIndex.value + 1) % count : -1;
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        selectedIndex.value = count > 0 ? (selectedIndex.value - 1 + count) % count : -1;
    } else if (e.key === 'Enter' && selectedIndex.value >= 0 && selectedIndex.value < count) {
        e.preventDefault();
        navigateToResult(items[selectedIndex.value]);
    } else if (e.key === 'Escape') {
        e.preventDefault();
        close();
    }
}

// Global keyboard shortcut
function handleGlobalKeydown(e) {
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        if (isOpen.value) {
            close();
        } else {
            open();
        }
    }
}

onMounted(() => {
    window.addEventListener('keydown', handleGlobalKeydown);
    loadRecentSearches();
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleGlobalKeydown);
    if (debounceTimer) clearTimeout(debounceTimer);
});

// Icon path for result type
function getIconPath(type) {
    const icons = {
        product: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
        order: 'M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z',
        customer: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
        supplier: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
        purchase_order: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01',
    };
    return icons[type] || icons.product;
}

// Track which selectable index a result item corresponds to
function getSelectableIndex(item) {
    return selectableResults.value.findIndex(r => r.id === item.id && r.type === item.type);
}

defineExpose({ open });
</script>

<template>
    <Teleport to="body">
        <!-- Backdrop -->
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="isOpen"
                class="fixed inset-0 z-[60] bg-black/50 backdrop-blur-sm"
                @click="close"
            ></div>
        </Transition>

        <!-- Modal -->
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
        >
            <div
                v-if="isOpen"
                class="fixed inset-0 z-[61] flex items-start justify-center pt-[15vh] px-4"
                @keydown="handleKeydown"
            >
                <div
                    class="w-full max-w-xl bg-white dark:bg-gray-800 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-700 overflow-hidden"
                    @click.stop
                >
                    <!-- Search Input -->
                    <div class="flex items-center px-4 border-b border-gray-200 dark:border-gray-700">
                        <svg class="w-5 h-5 text-gray-400 dark:text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <input
                            ref="searchInput"
                            v-model="query"
                            type="text"
                            placeholder="Search products, orders, customers..."
                            class="flex-1 px-3 py-4 bg-transparent border-0 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-0 focus:outline-none text-sm"
                        />
                        <div class="flex items-center gap-2">
                            <span v-if="isLoading" class="text-gray-400">
                                <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </span>
                            <kbd class="hidden sm:inline-flex items-center px-1.5 py-0.5 text-[10px] font-medium text-gray-400 dark:text-gray-500 bg-gray-100 dark:bg-gray-700 rounded border border-gray-200 dark:border-gray-600">
                                ESC
                            </kbd>
                        </div>
                    </div>

                    <!-- Results -->
                    <div class="max-h-80 overflow-y-auto">
                        <!-- Recent Searches -->
                        <div v-if="showRecent && !hasQuery && recentSearches.length > 0" class="py-2">
                            <div class="px-4 py-1.5">
                                <p class="text-[11px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Recent</p>
                            </div>
                            <button
                                v-for="item in recentSearches"
                                :key="item.type + '-' + item.id"
                                @click="navigateToResult(item)"
                                class="w-full flex items-center gap-3 px-4 py-2.5 text-left hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                            >
                                <svg class="w-4 h-4 text-gray-400 dark:text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-700 dark:text-gray-200 truncate">{{ item.title }}</p>
                                    <p v-if="item.subtitle" class="text-xs text-gray-400 dark:text-gray-500 truncate">{{ item.subtitle }}</p>
                                </div>
                            </button>
                        </div>

                        <!-- Categorized Results -->
                        <div v-if="hasQuery && hasResults" class="py-2">
                            <template v-for="(item, index) in flatResults" :key="index">
                                <!-- Category Header -->
                                <div v-if="item.type === 'header'" class="px-4 py-1.5 mt-1 first:mt-0">
                                    <p class="text-[11px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">{{ item.label }}</p>
                                </div>

                                <!-- Result Item -->
                                <button
                                    v-else
                                    @click="navigateToResult(item)"
                                    @mouseenter="selectedIndex = getSelectableIndex(item)"
                                    :class="[
                                        'w-full flex items-center gap-3 px-4 py-2.5 text-left transition-colors',
                                        getSelectableIndex(item) === selectedIndex
                                            ? 'bg-primary-50 dark:bg-primary-900/20'
                                            : 'hover:bg-gray-50 dark:hover:bg-gray-700/50'
                                    ]"
                                >
                                    <svg class="w-4 h-4 text-gray-400 dark:text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="getIconPath(item.icon || item.type)" />
                                    </svg>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-gray-700 dark:text-gray-200 truncate">{{ item.title }}</p>
                                        <p v-if="item.subtitle" class="text-xs text-gray-400 dark:text-gray-500 truncate">{{ item.subtitle }}</p>
                                    </div>
                                    <svg
                                        v-if="getSelectableIndex(item) === selectedIndex"
                                        class="w-4 h-4 text-gray-400 dark:text-gray-500 flex-shrink-0"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                    </svg>
                                </button>
                            </template>
                        </div>

                        <!-- No Results -->
                        <div v-if="hasQuery && !hasResults && !isLoading" class="py-10 text-center">
                            <svg class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No results found for "{{ query }}"</p>
                            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">Try a different search term</p>
                        </div>

                        <!-- Empty State -->
                        <div v-if="!hasQuery && recentSearches.length === 0" class="py-10 text-center">
                            <svg class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Start typing to search</p>
                            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">Search products, orders, customers, and more</p>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="px-4 py-2.5 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
                        <div class="flex items-center gap-3 text-xs text-gray-400 dark:text-gray-500">
                            <span class="inline-flex items-center gap-1">
                                <kbd class="px-1 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-[10px] border border-gray-200 dark:border-gray-600">↑</kbd>
                                <kbd class="px-1 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-[10px] border border-gray-200 dark:border-gray-600">↓</kbd>
                                navigate
                            </span>
                            <span class="inline-flex items-center gap-1">
                                <kbd class="px-1 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-[10px] border border-gray-200 dark:border-gray-600">↵</kbd>
                                open
                            </span>
                            <span class="inline-flex items-center gap-1">
                                <kbd class="px-1 py-0.5 bg-gray-100 dark:bg-gray-700 rounded text-[10px] border border-gray-200 dark:border-gray-600">esc</kbd>
                                close
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
