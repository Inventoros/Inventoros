<script setup>
/**
 * PortalLayout: the customer portal shell. Deliberately lighter than
 * AppLayout: the organization's name instead of the app chrome, three
 * destinations, and no staff navigation, search, warehouses or plugins.
 */
import FlashMessages from '@/Components/Layout/FlashMessages.vue';
import ThemeToggle from '@/Components/Layout/ThemeToggle.vue';
import LanguageSwitcher from '@/Components/LanguageSwitcher.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { LayoutDashboard, LogOut, Menu, Package, RotateCcw, X } from 'lucide-vue-next';

const { t } = useI18n();
const page = usePage();

const organization = computed(() => page.props.portal?.organization ?? {});
const contact = computed(() => page.props.portal?.contact ?? null);
const mobileOpen = ref(false);

const nav = computed(() => [
    { name: 'portal.dashboard', match: ['portal.dashboard'], label: t('portal.nav.dashboard'), icon: LayoutDashboard },
    { name: 'portal.orders.index', match: ['portal.orders.*', 'portal.returns.create'], label: t('portal.nav.orders'), icon: Package },
    { name: 'portal.returns.index', match: ['portal.returns.index', 'portal.returns.show'], label: t('portal.nav.returns'), icon: RotateCcw },
]);

const isActive = (item) => item.match.some((pattern) => route().current(pattern));

const signOut = () => router.post(route('portal.logout'));

const stopListening = router.on('start', () => {
    mobileOpen.value = false;
});
onBeforeUnmount(() => stopListening());

const initials = computed(() =>
    (organization.value.name || '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0].toUpperCase())
        .join('')
);
</script>

<template>
    <div class="flex min-h-screen flex-col bg-surface-canvas">
        <header class="sticky top-0 z-30 border-b border-border-subtle bg-surface-base/95 backdrop-blur">
            <div class="mx-auto flex h-14 max-w-6xl items-center gap-4 px-4 md:px-6">
                <Link :href="route('portal.dashboard')" class="flex min-w-0 items-center gap-2.5 rounded-md ds-focus-ring">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-brand text-xs font-semibold text-brand-foreground">
                        {{ initials }}
                    </span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-semibold text-text-primary">{{ organization.name }}</span>
                        <span class="block text-[11px] leading-tight text-text-tertiary">{{ t('portal.customerPortal') }}</span>
                    </span>
                </Link>

                <nav v-if="contact" class="ml-4 hidden items-center gap-1 md:flex">
                    <Link
                        v-for="item in nav"
                        :key="item.name"
                        :href="route(item.name)"
                        :class="[
                            'inline-flex h-8 items-center gap-1.5 whitespace-nowrap rounded-md px-3 text-sm font-medium transition-colors ds-focus-ring',
                            isActive(item)
                                ? 'bg-surface-overlay text-text-primary'
                                : 'text-text-secondary hover:bg-surface-overlay hover:text-text-primary',
                        ]"
                    >
                        <component :is="item.icon" :size="15" />
                        {{ item.label }}
                    </Link>
                </nav>

                <div class="ml-auto flex items-center gap-1">
                    <LanguageSwitcher />
                    <ThemeToggle />
                    <template v-if="contact">
                        <div class="ml-2 hidden text-right lg:block">
                            <p class="max-w-48 truncate text-xs font-medium text-text-primary">{{ contact.name }}</p>
                            <p v-if="contact.customer" class="max-w-48 truncate text-[11px] text-text-tertiary">{{ contact.customer }}</p>
                        </div>
                        <button
                            type="button"
                            class="ml-1 hidden h-8 items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 text-sm text-text-secondary hover:bg-surface-overlay hover:text-text-primary ds-focus-ring md:inline-flex"
                            :aria-label="t('portal.nav.signOut')"
                            :title="t('portal.nav.signOut')"
                            @click="signOut"
                        >
                            <LogOut :size="15" />
                            <!-- Icon only at md: the nav plus a long (French) label overflowed a tablet. -->
                            <span class="hidden lg:inline">{{ t('portal.nav.signOut') }}</span>
                        </button>
                        <button
                            type="button"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-text-secondary hover:bg-surface-overlay ds-focus-ring md:hidden"
                            :aria-label="t('portal.nav.menu')"
                            :aria-expanded="mobileOpen"
                            @click="mobileOpen = !mobileOpen"
                        >
                            <X v-if="mobileOpen" :size="18" />
                            <Menu v-else :size="18" />
                        </button>
                    </template>
                </div>
            </div>

            <nav v-if="contact && mobileOpen" class="border-t border-border-subtle px-4 py-2 md:hidden">
                <Link
                    v-for="item in nav"
                    :key="item.name"
                    :href="route(item.name)"
                    :class="[
                        'flex h-10 items-center gap-2 rounded-md px-3 text-sm font-medium',
                        isActive(item) ? 'bg-surface-overlay text-text-primary' : 'text-text-secondary',
                    ]"
                >
                    <component :is="item.icon" :size="16" />
                    {{ item.label }}
                </Link>
                <div class="mt-2 border-t border-border-subtle pt-2">
                    <p class="px-3 text-xs text-text-tertiary">{{ contact.name }}<span v-if="contact.customer"> · {{ contact.customer }}</span></p>
                    <button type="button" class="mt-1 flex h-10 w-full items-center gap-2 rounded-md px-3 text-sm text-text-secondary" @click="signOut">
                        <LogOut :size="16" />
                        {{ t('portal.nav.signOut') }}
                    </button>
                </div>
            </nav>
        </header>

        <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-6 md:px-6 md:py-8">
            <div>
                <slot />
            </div>
        </main>

        <footer class="border-t border-border-subtle">
            <div class="mx-auto flex max-w-6xl flex-col gap-1 px-4 py-5 text-xs text-text-tertiary md:flex-row md:justify-between md:px-6">
                <span>{{ organization.name }}</span>
                <a v-if="organization.email" :href="`mailto:${organization.email}`" class="hover:text-text-secondary">{{ organization.email }}</a>
            </div>
        </footer>

        <FlashMessages />
    </div>
</template>
