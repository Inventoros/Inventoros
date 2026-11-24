import { ref } from 'vue';

/**
 * The sidebar section last navigated from. Kept at module level so it
 * survives Inertia page changes (AppLayout remounts per page) and is used on
 * pages that belong to no section.
 */
export const lastNavSection = ref(null);

/**
 * Whether an Inertia visit (router 'start' event) leaves the current page.
 * Partial reloads (only/except) and reloads of the same path, such as a
 * page polling for status, keep open menus open.
 */
export function leavesPage(event) {
    const visit = event?.detail?.visit;
    if (!visit) return true;
    if (visit.only?.length || visit.except?.length) return false;
    try {
        return new URL(visit.url, window.location.href).pathname !== window.location.pathname;
    } catch {
        return true;
    }
}
