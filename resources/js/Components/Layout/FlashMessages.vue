<script setup>
/**
 * FlashMessages — the one place session flash reaches the user.
 *
 * Controllers redirect with ->with('success'|'error'|'warning'|'status', ...)
 * in hundreds of places across the app, and OrderController in particular
 * documents flashing as its deliberate alternative to a 500 ("flash an error
 * rather than 500ing"). None of it was ever rendered, so a failed order looked
 * like a silent no-op. This mounts once in AppLayout and covers all of it.
 *
 * Only plain, non-empty strings are shown. A page that flashes a structured
 * payload renders it itself — the importer flashes ['message' => ..., 'stats'
 * => [...]] and draws a per-row breakdown, which a toast cannot represent and
 * would stringify into nonsense.
 *
 * Errors and warnings persist until dismissed; successes and statuses clear
 * themselves. The whole reason this component exists is that failures were
 * going unseen, so a failure is never taken off the screen on a timer.
 */
import { onBeforeUnmount, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { CircleCheck, CircleAlert, Info, TriangleAlert, X } from 'lucide-vue-next';

const { t } = useI18n();
const page = usePage();

const AUTO_DISMISS_MS = 6000;

const VARIANTS = {
    success: {
        icon: CircleCheck,
        role: 'status',
        autoDismiss: true,
        tone: 'border-status-success/25 bg-status-success-soft text-status-success',
    },
    error: {
        icon: CircleAlert,
        role: 'alert',
        autoDismiss: false,
        tone: 'border-status-danger/25 bg-status-danger-soft text-status-danger',
    },
    warning: {
        icon: TriangleAlert,
        role: 'alert',
        autoDismiss: false,
        tone: 'border-status-warning/25 bg-status-warning-soft text-status-warning',
    },
    status: {
        icon: Info,
        role: 'status',
        autoDismiss: true,
        tone: 'border-status-info/25 bg-status-info-soft text-status-info',
    },
};

const messages = ref([]);
const timers = new Map();
let nextId = 0;

function dismiss(id) {
    const timer = timers.get(id);

    if (timer) {
        clearTimeout(timer);
        timers.delete(id);
    }

    messages.value = messages.value.filter((message) => message.id !== id);
}

function push(type, text) {
    const variant = VARIANTS[type];
    const id = ++nextId;

    messages.value = [...messages.value, { id, type, text, ...variant }];

    if (variant.autoDismiss) {
        timers.set(id, setTimeout(() => dismiss(id), AUTO_DISMISS_MS));
    }
}

watch(
    () => page.props.flash,
    (flash) => {
        if (!flash) {
            return;
        }

        for (const type of Object.keys(VARIANTS)) {
            const value = flash[type];

            if (typeof value === 'string' && value.trim() !== '') {
                push(type, value);
            }
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    timers.forEach((timer) => clearTimeout(timer));
    timers.clear();
});

defineExpose({ messages });
</script>

<template>
    <div
        v-if="messages.length"
        class="fixed bottom-4 right-4 z-50 flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-2"
        data-testid="flash-messages"
    >
        <TransitionGroup
            enter-from-class="opacity-0 translate-y-1"
            enter-active-class="transition duration-150 motion-reduce:transition-none"
            leave-to-class="opacity-0"
            leave-active-class="transition duration-100 motion-reduce:transition-none"
        >
            <div
                v-for="message in messages"
                :key="message.id"
                :role="message.role"
                :class="[
                    'flex items-start gap-2 rounded-md border px-3 py-2 shadow-sm',
                    message.tone,
                ]"
            >
                <component :is="message.icon" :size="14" class="mt-0.5 shrink-0" aria-hidden="true" />
                <p class="min-w-0 flex-1 text-[13px] leading-snug">{{ message.text }}</p>
                <button
                    type="button"
                    class="-mr-1 -mt-0.5 shrink-0 rounded p-0.5 opacity-60 transition-opacity hover:opacity-100 motion-reduce:transition-none"
                    :aria-label="t('common.dismiss')"
                    @click="dismiss(message.id)"
                >
                    <X :size="14" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
