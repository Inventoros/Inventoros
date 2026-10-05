<script setup>
/**
 * Organization picker for the top strip.
 *
 * Shown only to users who belong to two or more organizations. Switching
 * posts to the server, which checks the membership, regenerates the session
 * and lands on the dashboard of the chosen organization.
 */
import { computed, ref, onMounted, onUnmounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Building2, Check, ChevronDown } from 'lucide-vue-next';

const { t } = useI18n();
const page = usePage();
const isOpen = ref(false);
const dropdownRef = ref(null);

const organizations = computed(() => page.props.auth?.organizations || []);
const active = computed(() => page.props.auth?.organization || null);

const switchTo = (organization) => {
    isOpen.value = false;
    if (!organization || organization.id === active.value?.id) return;

    router.post(route('organizations.switch'), { organization_id: organization.id }, {
        preserveState: false,
    });
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
    <div v-if="organizations.length > 1" ref="dropdownRef" class="sm:relative" data-testid="organization-switcher">
        <button
            type="button"
            @click="isOpen = !isOpen"
            class="flex items-center gap-1.5 h-8 px-2 rounded-md text-xs text-text-secondary hover:text-text-primary hover:bg-surface-overlay transition-colors ds-focus-ring"
            :aria-expanded="isOpen"
            aria-haspopup="listbox"
            :aria-label="t('components.organizationSwitcher.ariaLabel', { name: active?.name || '' })"
            :title="active?.name || ''"
        >
            <Building2 :size="15" />
            <span class="hidden lg:inline max-w-[160px] truncate">{{ active?.name }}</span>
            <ChevronDown :size="14" class="transition-transform duration-200" :class="isOpen ? 'rotate-180' : ''" />
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
                :aria-label="t('components.organizationSwitcher.listLabel')"
                class="absolute inset-x-4 top-full mt-1 sm:inset-x-auto sm:right-0 sm:mt-2 sm:w-64 bg-surface-raised border border-border-subtle rounded-lg shadow-xl overflow-hidden z-50 max-h-80 overflow-y-auto ds-scroll"
            >
                <p class="px-3 pt-2.5 pb-1.5 text-[11px] font-medium uppercase tracking-wide text-text-tertiary">
                    {{ t('components.organizationSwitcher.heading') }}
                </p>
                <button
                    v-for="organization in organizations"
                    :key="organization.id"
                    type="button"
                    role="option"
                    :aria-selected="active?.id === organization.id"
                    @click="switchTo(organization)"
                    :class="[
                        'w-full flex items-center gap-3 px-3 py-2 text-sm transition-colors',
                        active?.id === organization.id
                            ? 'bg-brand/10 text-brand'
                            : 'text-text-secondary hover:text-text-primary hover:bg-surface-overlay'
                    ]"
                >
                    <span class="flex-1 min-w-0 text-left truncate">{{ organization.name }}</span>
                    <Check v-if="active?.id === organization.id" :size="14" class="shrink-0" />
                </button>
            </div>
        </Transition>
    </div>
</template>
