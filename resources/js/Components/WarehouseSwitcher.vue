<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

const page = usePage();

const warehouses = computed(() => page.props.warehouses || []);
const activeWarehouseId = computed(() => page.props.activeWarehouseId || null);

const activeWarehouse = computed(() => {
    if (!activeWarehouseId.value) return null;
    return warehouses.value.find(w => w.id === activeWarehouseId.value) || null;
});

const isOpen = ref(false);
const dropdownRef = ref(null);

const toggleDropdown = () => {
    isOpen.value = !isOpen.value;
};

const selectWarehouse = (warehouseId) => {
    isOpen.value = false;
    router.post(route('set-warehouse'), {
        warehouse_id: warehouseId,
    }, {
        preserveState: false,
    });
};

const handleClickOutside = (event) => {
    if (dropdownRef.value && !dropdownRef.value.contains(event.target)) {
        isOpen.value = false;
    }
};

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
    <div v-if="warehouses.length > 0" class="relative" ref="dropdownRef">
        <button
            @click="toggleDropdown"
            class="flex items-center gap-2 px-3 py-2 text-sm text-gray-600 dark:text-gray-300 hover:text-primary-600 hover:bg-gray-100 dark:hover:text-primary-400 dark:hover:bg-dark-card rounded-lg transition"
        >
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z" />
            </svg>
            <span class="max-w-[140px] truncate">{{ activeWarehouse ? activeWarehouse.name : 'All Warehouses' }}</span>
            <svg class="w-4 h-4 flex-shrink-0 transition-transform duration-200" :class="isOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <!-- Dropdown -->
        <div
            v-show="isOpen"
            class="absolute right-0 mt-2 w-64 bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg shadow-xl z-50 overflow-hidden"
        >
            <div class="py-1">
                <!-- All Warehouses option -->
                <button
                    @click="selectWarehouse(null)"
                    :class="[
                        'w-full flex items-center gap-3 px-4 py-2.5 text-sm transition',
                        !activeWarehouseId
                            ? 'bg-primary-400/10 text-primary-400'
                            : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-dark-bg/50'
                    ]"
                >
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                    </svg>
                    <span class="font-medium">All Warehouses</span>
                    <svg v-if="!activeWarehouseId" class="w-4 h-4 ml-auto text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                </button>

                <div class="border-t border-gray-200 dark:border-dark-border"></div>

                <!-- Individual warehouses -->
                <button
                    v-for="warehouse in warehouses"
                    :key="warehouse.id"
                    @click="selectWarehouse(warehouse.id)"
                    :class="[
                        'w-full flex items-center gap-3 px-4 py-2.5 text-sm transition',
                        activeWarehouseId === warehouse.id
                            ? 'bg-primary-400/10 text-primary-400'
                            : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-dark-bg/50'
                    ]"
                >
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z" />
                    </svg>
                    <div class="flex-1 text-left min-w-0">
                        <div class="font-medium truncate">{{ warehouse.name }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ warehouse.code }}</div>
                    </div>
                    <svg v-if="activeWarehouseId === warehouse.id" class="w-4 h-4 ml-auto text-primary-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                </button>
            </div>
        </div>
    </div>
</template>
