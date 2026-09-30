<script setup>
/**
 * Language picker for the top strip.
 *
 * Switches vue-i18n immediately and writes the `locale` cookie (via
 * setLocale). For a signed-in user it also saves the choice as their
 * language preference, because SetLocale prefers the saved preference over
 * the cookie: without saving, the next page load would switch back.
 */
import { computed, ref, onMounted, onUnmounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Languages, Check } from 'lucide-vue-next';
import { availableLocales, setLocale } from '@/i18n';

const { locale, t } = useI18n();
const page = usePage();
const isOpen = ref(false);
const dropdownRef = ref(null);

const current = computed(() => availableLocales.find((l) => l.code === locale.value) || availableLocales[0]);

const switchLocale = (code) => {
    isOpen.value = false;
    if (code === locale.value) return;

    setLocale(code);

    if (page.props.auth?.user) {
        router.patch(route('settings.account.update.locale'), { locale: code }, {
            preserveScroll: true,
            preserveState: true,
        });
    }
};

const handleClickOutside = (event) => {
    if (dropdownRef.value && !dropdownRef.value.contains(event.target)) {
        isOpen.value = false;
    }
};

onMounted(() => document.addEventListener('click', handleClickOutside));
onUnmounted(() => document.removeEventListener('click', handleClickOutside));
</script>

<template>
    <div ref="dropdownRef" class="relative">
        <button
            type="button"
            @click="isOpen = !isOpen"
            class="flex items-center gap-1.5 h-8 px-2 rounded-md text-xs text-text-secondary hover:text-text-primary hover:bg-surface-overlay transition-colors ds-focus-ring"
            :aria-expanded="isOpen"
            aria-haspopup="listbox"
            :aria-label="t('components.languageSwitcher.ariaLabel', { name: current.name })"
        >
            <Languages :size="15" />
            <span class="hidden sm:inline uppercase">{{ current.code }}</span>
        </button>

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
                class="absolute right-0 top-full mt-2 w-48 bg-surface-raised border border-border-subtle rounded-lg shadow-xl overflow-hidden z-50 max-h-80 overflow-y-auto ds-scroll"
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
                    <span class="flex-1 text-left">{{ loc.name }}</span>
                    <Check v-if="locale === loc.code" :size="14" />
                </button>
            </div>
        </Transition>
    </div>
</template>
