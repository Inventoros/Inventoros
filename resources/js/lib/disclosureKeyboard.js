import { nextTick } from 'vue';

/** Keyboard behavior shared by organization and warehouse selection menus. */
export async function disclosureKeydown(event, root, open, trigger) {
    if (event.key === 'Escape' && open.value) {
        event.preventDefault();
        event.stopPropagation();
        open.value = false;
        trigger?.focus();
        return;
    }
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const wasOpen = open.value;
    open.value = true;
    await nextTick();
    const items = [...(root?.querySelectorAll('[role="option"], [role="menuitemradio"]') || [])];
    if (!items.length) return;
    const current = items.indexOf(document.activeElement);
    const selected = items.findIndex(item => item.getAttribute('aria-selected') === 'true' || item.getAttribute('aria-checked') === 'true');
    const index = !wasOpen ? Math.max(selected, 0) : event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : (current + (event.key === 'ArrowUp' ? -1 : 1) + items.length) % items.length;
    items[index]?.focus();
}
