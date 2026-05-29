<script setup>
import { ref } from 'vue';
import GlobalSearch from '@/Components/Layout/GlobalSearch.vue';
import NotificationDropdown from '@/Components/Layout/NotificationDropdown.vue';
import ThemeToggle from '@/Components/Layout/ThemeToggle.vue';
import AppSidebar from '@/Components/Layout/AppSidebar.vue';
import WarehouseSwitcher from '@/Components/WarehouseSwitcher.vue';
import { useSidebar } from '@/composables/useSidebar';

const { sidebarCollapsed, sidebarOpen } = useSidebar();
const globalSearchRef = ref(null);
</script>

<template>
    <div class="min-h-screen bg-gray-50 dark:bg-dark-bg">
        <!-- Sidebar (with mobile overlay) -->
        <AppSidebar />

        <!-- Main content area -->
        <div :class="sidebarCollapsed ? 'lg:pl-[68px]' : 'lg:pl-64'" class="transition-all duration-200">
            <!-- Page Heading -->
            <header
                class="sticky top-0 z-40 bg-white/80 dark:bg-dark-bg/80 backdrop-blur-md border-b border-gray-200 dark:border-dark-border"
                v-if="$slots.header"
            >
                <div class="flex items-center justify-between h-16 px-4 sm:px-6 lg:px-8">
                    <!-- Mobile Menu Button -->
                    <button
                        @click="sidebarOpen = !sidebarOpen"
                        class="lg:hidden p-2 text-gray-500 hover:text-primary-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:text-primary-400 dark:hover:bg-dark-card rounded-lg transition"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>

                    <!-- Header Content -->
                    <div class="flex-1">
                        <slot name="header" />
                    </div>

                    <!-- Header Actions -->
                    <div class="flex items-center gap-1 ml-4">
                        <WarehouseSwitcher />
                        <!-- Search Button -->
                        <button
                            @click="globalSearchRef?.open()"
                            class="p-2 text-gray-500 hover:text-primary-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:text-primary-400 dark:hover:bg-dark-card rounded-lg transition"
                            title="Search (Ctrl+K)"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </button>
                        <ThemeToggle />
                        <NotificationDropdown />
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <main>
                <slot />
            </main>
        </div>

        <!-- Global Search -->
        <GlobalSearch ref="globalSearchRef" />
    </div>
</template>
