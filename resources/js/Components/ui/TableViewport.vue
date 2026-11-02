<script setup>
import { nextTick, onBeforeUnmount, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { Maximize2, X } from '@lucide/vue';
import { lockPageScroll } from '@/lib/scrollLock';
import { containTabFocus } from '@/lib/focus';

defineOptions({ inheritAttrs: false });
const props = defineProps({ label: { type: String, default: null } });
const { t } = useI18n();
const id = `table-view-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`;
const dialog = ref(null);
const expandButton = ref(null);
const closeButton = ref(null);
const viewport = ref(null);
const expanded = ref(false);
const caption = ref('');
let releaseScroll;
let scrollBefore;
let modalObserver;
let openedActionDialog = false;
const restoreBody = () => {
    releaseScroll?.();
    releaseScroll = undefined;
};

const open = async () => {
    const existingDialogs = [...document.querySelectorAll('dialog[open], [role="dialog"], .fixed.inset-0.z-50')]
        .filter(element => element.getClientRects().length);
    caption.value =
        props.label ||
        document.querySelector('h1')?.textContent.trim() ||
        t('common.results');
    scrollBefore = {
        top: viewport.value.scrollTop,
        left: viewport.value.scrollLeft,
    };
    openedActionDialog = false;
    releaseScroll = lockPageScroll();
    expanded.value = true;
    await nextTick();
    // The native modal provides focus containment, Escape and an inert
    // background. Teleport moves the existing table, preserving edited cells.
    dialog.value.showModal();
    closeButton.value?.focus();
    // Existing row actions can open application modals teleported to body.
    // Leave the native top layer so those dialogs remain interactive.
    modalObserver = new MutationObserver(() => {
        const actionDialog = [
            ...document.querySelectorAll(
                'dialog[open], [role="dialog"], .fixed.inset-0.z-50',
            ),
        ].find(
            (element) =>
                element !== dialog.value &&
                !existingDialogs.some(existing => existing === element || existing.contains(element)) &&
                !dialog.value.contains(element) &&
                element.getClientRects().length,
        );
        if (actionDialog && dialog.value.open) {
            openedActionDialog = true;
            dialog.value.close();
        } else if (openedActionDialog && !actionDialog) {
            restoreBody();
            modalObserver.disconnect();
        }
    });
    modalObserver.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['class', 'style', 'open'],
    });
};
const restore = async () => {
    if (!openedActionDialog) modalObserver?.disconnect();
    expanded.value = false;
    if (!openedActionDialog) restoreBody();
    await nextTick();
    if (viewport.value && scrollBefore) {
        viewport.value.scrollTop = scrollBefore.top;
        viewport.value.scrollLeft = scrollBefore.left;
    }
    if (!openedActionDialog) expandButton.value?.focus({ preventScroll: true });
};
onBeforeUnmount(() => {
    modalObserver?.disconnect();
    if (dialog.value?.open) dialog.value.close();
    restoreBody();
});
</script>

<template>
    <dialog
        :id="id"
        ref="dialog"
        class="table-full-dialog"
        :aria-labelledby="`${id}-title`"
        @close="restore"
        @keydown="containTabFocus($event, dialog)"
        @keydown.esc.stop.prevent="dialog.close()"
    >
        <!-- The live table moves here when expanded; it is never duplicated. -->
    </dialog>
    <Teleport :to="expanded ? dialog : 'body'" :disabled="!expanded">
        <section
            v-bind="$attrs"
            class="table-panel"
            :class="{ 'table-panel-expanded': expanded }"
        >
            <header v-if="expanded" class="table-full-heading">
                <h2 :id="`${id}-title`">{{ caption }}</h2>
                <button
                    ref="closeButton"
                    type="button"
                    class="table-view-button ds-focus-ring"
                    :aria-label="t('common.collapse')"
                    @click="dialog.close()"
                >
                    <X :size="18" aria-hidden="true" />
                </button>
            </header>
            <button
                v-else
                ref="expandButton"
                type="button"
                class="table-expand-button table-view-button ds-focus-ring"
                :title="t('common.expandTable')"
                :aria-label="t('common.expandTable')"
                aria-haspopup="dialog"
                :aria-controls="id"
                @click="open"
            >
                <Maximize2 :size="14" aria-hidden="true" />
            </button>
            <div
                ref="viewport"
                class="table-viewport ds-scroll ds-focus-ring"
                role="region"
                :aria-label="label || t('common.results')"
                tabindex="0"
                scroll-region
            >
                <slot />
            </div>
            <div v-if="$slots.footer" class="table-panel-footer">
                <slot name="footer" />
            </div>
        </section>
    </Teleport>
</template>
