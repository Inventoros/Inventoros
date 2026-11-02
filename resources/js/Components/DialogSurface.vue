<script setup>
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { lockPageScroll } from '@/lib/scrollLock';
import { containTabFocus } from '@/lib/focus';

// The visual panel stays with its caller. This surface supplies native focus
// containment, an inert background, Escape and focus restoration consistently.
const props = defineProps({ show: Boolean, label: { type: String, default: null }, closeable: { type: Boolean, default: true } });
const emit = defineEmits(['close']);
const surface = ref(null);
const titleId = `dialog-title-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`;
let previousFocus;
let releaseScroll;
let labelledBy;

const release = () => {
    releaseScroll?.();
    releaseScroll = undefined;
};
const requestClose = () => { if (props.closeable) emit('close'); };

watch(() => props.show, async (show) => {
    if (show) {
        previousFocus = document.activeElement;
        const disclosure = previousFocus?.closest('.page-header-actions')?.querySelector('.page-header-action-toggle');
        if (disclosure?.getClientRects().length) previousFocus = disclosure;
        await nextTick();
        if (!props.show || !surface.value) return;
        const heading = surface.value.querySelector('h1, h2, h3, h4');
        if (heading && !props.label) {
            heading.id ||= titleId;
            labelledBy = heading.id;
            surface.value.setAttribute('aria-labelledby', labelledBy);
        }
        releaseScroll = lockPageScroll();
        surface.value.showModal();
        // Tall fixed panels can make Chromium initially focus the dialog
        // itself. Start on a real control and keep reverse Tab inside it.
        const initial = surface.value.querySelector('[autofocus]') || surface.value.querySelector('button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), a[href]');
        initial?.focus({ preventScroll: true });
    } else {
        surface.value?.close();
        release();
        await nextTick();
        if (previousFocus?.isConnected && previousFocus.getClientRects().length)
            previousFocus.focus({ preventScroll: true });
    }
}, { immediate: true, flush: 'post' });

onBeforeUnmount(() => {
    surface.value?.close();
    release();
});
</script>

<template>
    <Teleport to="body">
        <dialog ref="surface" class="dialog-surface" :aria-label="label || undefined" @cancel.prevent="requestClose" @keydown="containTabFocus($event, surface)" @keydown.esc.stop.prevent="requestClose">
            <slot v-if="show" />
        </dialog>
    </Teleport>
</template>

<style>
.dialog-surface {
    position: fixed;
    inset: 0;
    width: 100%;
    height: 100dvh;
    max-width: none;
    max-height: none;
    margin: 0;
    padding: 0;
    border: 0;
    overflow: auto;
    background: transparent;
    color: inherit;
}
.dialog-surface::backdrop { background: transparent; }
/* Keep long forms reachable on short screens, including the on-screen keyboard. */
.dialog-surface > .fixed { overflow-y: auto; }
.dialog-surface > .fixed.items-center { align-items: safe center; padding-block: 1rem; }
</style>
