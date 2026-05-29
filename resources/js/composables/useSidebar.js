import { ref } from 'vue';

// Shared sidebar UI state, lifted out of AuthenticatedLayout.vue (P2-9) so the
// layout shell, the sidebar component, and the topbar's mobile toggle all read
// and write the same state. Module-scoped refs make this a singleton for the
// life of the SPA session.
const sidebarCollapsed = ref(localStorage.getItem('sidebar-collapsed') === 'true');
const sidebarOpen = ref(false);

function setCollapsed(value) {
    sidebarCollapsed.value = value;
    localStorage.setItem('sidebar-collapsed', value ? 'true' : 'false');
}

export function useSidebar() {
    return { sidebarCollapsed, sidebarOpen, setCollapsed };
}
