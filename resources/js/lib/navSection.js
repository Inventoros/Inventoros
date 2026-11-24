import { ref } from 'vue';

/**
 * The sidebar section last picked from the rail. Kept at module level so it
 * survives Inertia page changes (AppLayout remounts per page) and is used on
 * pages that belong to no section.
 */
export const lastNavSection = ref(null);
