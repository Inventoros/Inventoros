<script setup>
/**
 * Account menu at the foot of the sidebar rail: the signed-in user's
 * initial opens a small panel with their name, a link to account settings
 * and Log Out. A disclosure, not an ARIA menu: Tab moves through it,
 * Escape closes it and returns focus to the avatar.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { leavesPage } from '@/lib/navSection';
import { useI18n } from 'vue-i18n';
import { LogOut, UserRound } from '@lucide/vue';

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth?.user);
// First and last initials ("Alex Morgan" -> "AM"), or one for a single name.
const initials = computed(() => {
    const words = (user.value?.name || '?').trim().split(/\s+/);
    const letters = words.length > 1 ? words[0].charAt(0) + words[words.length - 1].charAt(0) : words[0].charAt(0);
    return letters.toUpperCase();
});

const open = ref(false);
const root = ref(null);
const trigger = ref(null);
const panel = ref(null);

const toggle = async (event) => {
    open.value = !open.value;
    if (open.value && event?.detail === 0) {
        await nextTick();
        panel.value?.querySelector('a[href], button')?.focus();
    }
};

const close = (returnFocus = false) => {
    if (!open.value) return;
    open.value = false;
    if (returnFocus) trigger.value?.focus();
};

const onKeydown = (event) => {
    if (event.key === 'Escape' && open.value) {
        event.preventDefault();
        event.stopPropagation();
        close(true);
    }
};

const onPointerDown = (event) => {
    if (open.value && root.value && !root.value.contains(event.target)) close();
};

let removeNavigationListener;
onMounted(() => {
    document.addEventListener('pointerdown', onPointerDown);
    removeNavigationListener = router.on('start', (event) => { if (leavesPage(event)) close(); });
});
onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onPointerDown);
    removeNavigationListener?.();
});
</script>

<template>
    <div
        ref="root"
        class="workspace-account"
        @keydown="onKeydown"
        @focusout="event => { if (open && event.relatedTarget && !root?.contains(event.relatedTarget)) close(); }"
    >
        <button
            ref="trigger"
            type="button"
            class="workspace-rail-button workspace-rail-avatar"
            data-testid="user-menu"
            :aria-label="`${t('nav.accountMenu')}, ${user?.name || ''}`"
            :aria-expanded="open"
            aria-controls="workspace-account-panel"
            @click="toggle"
        >
            <span aria-hidden="true">{{ initials }}</span>
            <span class="workspace-rail-tooltip" aria-hidden="true">{{ user?.name }}</span>
        </button>
        <div
            v-if="open"
            id="workspace-account-panel"
            ref="panel"
            class="workspace-account-panel"
        >
            <div class="px-3 pt-3 pb-2 border-b border-border-subtle">
                <p class="text-[13px] font-medium text-text-primary truncate">{{ user?.name }}</p>
                <p class="text-[11px] text-text-tertiary truncate">{{ user?.email }}</p>
            </div>
            <div class="p-1.5">
                <Link
                    :href="route('settings.account.index')"
                    class="flex items-center gap-2.5 w-full h-9 px-2.5 rounded-md text-[13px] text-text-secondary hover:bg-surface-overlay hover:text-text-primary ds-focus-ring"
                >
                    <UserRound :size="16" :stroke-width="1.65" aria-hidden="true" />
                    {{ t('nav.account') }}
                </Link>
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    data-testid="logout"
                    class="flex items-center gap-2.5 w-full h-9 px-2.5 rounded-md text-[13px] text-text-secondary hover:bg-surface-overlay hover:text-text-primary ds-focus-ring"
                >
                    <LogOut :size="16" :stroke-width="1.65" aria-hidden="true" />
                    {{ t('nav.logOut') }}
                </Link>
            </div>
        </div>
    </div>
</template>
