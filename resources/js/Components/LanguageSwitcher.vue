<script setup>
/**
 * Language picker for the top strip.
 *
 * Loads a language before switching vue-i18n and writing the `locale` cookie
 * (via setLocale). For a signed-in user it also saves the choice as their
 * language preference, because SetLocale prefers the saved preference over
 * the cookie: without saving, the next page load would switch back.
 */
import { computed, nextTick, ref, onMounted, onUnmounted, useId } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Languages, Check, LoaderCircle } from '@lucide/vue';
import { availableLocales, setLocale } from '@/i18n';

const { locale, t } = useI18n();
const page = usePage();
const isOpen = ref(false);
const dropdownRef = ref(null);
const triggerRef = ref(null);
const listId = `languages-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`;
const isLoading = ref(false);
const failedLocale = ref(null);
let switchRequest = 0;

const close = (restoreFocus = false) => {
    isOpen.value = false;
    if (restoreFocus) triggerRef.value?.focus();
};
const openWithKeyboard = async () => {
    failedLocale.value = null;
    isOpen.value = true;
    await nextTick();
    dropdownRef.value?.querySelector('[aria-selected="true"]')?.focus();
};
const handleKeydown = (event) => {
    if (event.key === 'Escape' && (isOpen.value || failedLocale.value)) {
        event.preventDefault();
        event.stopPropagation();
        failedLocale.value = null;
        close(true);
    } else if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
        event.preventDefault();
        if (!isOpen.value) return openWithKeyboard();
        const options = [...dropdownRef.value.querySelectorAll('[role="option"]')];
        const index = options.indexOf(document.activeElement);
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? options.length - 1 : (index + (event.key === 'ArrowUp' ? -1 : 1) + options.length) % options.length;
        options[next]?.focus();
    }
};

const current = computed(() => availableLocales.find((l) => l.code === locale.value) || availableLocales[0]);

const switchLocale = async (code) => {
    close(true);
    if (code === locale.value && !isLoading.value) return;
    const request = ++switchRequest;
    failedLocale.value = null;
    isLoading.value = true;

    try {
        const applied = await setLocale(code);
        if (!applied || request !== switchRequest) return;

        if (page.props.auth?.user) {
            router.patch(route('settings.account.update.locale'), { locale: code }, {
                preserveScroll: true,
                preserveState: true,
            });
        }
    } catch {
        if (request === switchRequest) failedLocale.value = code;
    } finally {
        if (request === switchRequest) isLoading.value = false;
    }
};

const handleClickOutside = (event) => {
    if (dropdownRef.value && !dropdownRef.value.contains(event.target)) {
        isOpen.value = false;
    }
};

onMounted(() => document.addEventListener('click', handleClickOutside));
onUnmounted(() => {
    switchRequest++;
    document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
    <div ref="dropdownRef" class="relative" @keydown="handleKeydown" @focusout="event => { if (!dropdownRef.contains(event.relatedTarget)) close(); }">
        <button
            ref="triggerRef"
            :id="`${listId}-trigger`"
            type="button"
            @click="failedLocale = null; isOpen = !isOpen"
            class="relative z-50 flex items-center gap-1.5 h-8 px-2 rounded-md text-xs text-text-secondary hover:text-text-primary hover:bg-surface-overlay transition-colors ds-focus-ring"
            :aria-expanded="isOpen"
            :aria-busy="isLoading"
            aria-haspopup="listbox"
            :aria-controls="isOpen ? listId : undefined"
            :aria-label="t('components.languageSwitcher.ariaLabel', { name: current.name })"
        >
            <LoaderCircle v-if="isLoading" :size="15" class="animate-spin motion-reduce:animate-none" aria-hidden="true" />
            <Languages v-else :size="15" aria-hidden="true" />
            <span class="hidden sm:inline uppercase">{{ current.code }}</span>
        </button>

        <span class="sr-only" role="status" :aria-label="t('components.languageSwitcher.ariaLabel', { name: current.name })">{{ isLoading ? t('common.loading') : '' }}</span>
        <div
            v-if="failedLocale"
            role="alert"
            class="absolute end-0 top-full z-50 mt-2 w-64 max-w-[calc(100vw-2rem)] rounded-lg border border-border-subtle bg-surface-raised p-3 text-sm text-text-primary shadow-xl"
        >
            <p>{{ t('components.languageSwitcher.loadError') }}</p>
            <div class="mt-2 flex items-center gap-3">
                <button type="button" class="min-h-8 text-brand ds-focus-ring rounded" @click="switchLocale(failedLocale)">{{ t('common.retry') }}</button>
                <button type="button" class="min-h-8 text-text-secondary ds-focus-ring rounded" @click="failedLocale = null; triggerRef?.focus()">{{ t('common.dismiss') }}</button>
            </div>
        </div>

        <div v-if="isOpen" class="fixed inset-0 z-40" aria-hidden="true" @click="close(true)" />

        <Transition
            enter-active-class="transition ease-out duration-150"
            enter-from-class="opacity-0 -translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-100"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-1"
        >
            <div
                v-if="isOpen"
                role="listbox"
                :id="listId"
                :aria-labelledby="`${listId}-trigger`"
                class="absolute end-0 top-full mt-2 w-48 bg-surface-raised border border-border-subtle rounded-lg shadow-xl overflow-hidden z-50 max-h-80 overflow-y-auto ds-scroll"
            >
                <button
                    v-for="loc in availableLocales"
                    :key="loc.code"
                    type="button"
                    role="option"
                    :aria-selected="locale === loc.code"
                    :lang="loc.code"
                    @click="switchLocale(loc.code)"
                    :class="[
                        'w-full flex items-center gap-3 px-3 py-2 text-sm transition-colors',
                        locale === loc.code
                            ? 'bg-brand/10 text-brand'
                            : 'text-text-secondary hover:text-text-primary hover:bg-surface-overlay'
                    ]"
                >
                    <span class="flex-1 text-start">{{ loc.name }}</span>
                    <Check v-if="locale === loc.code" :size="14" />
                </button>
            </div>
        </Transition>
    </div>
</template>
