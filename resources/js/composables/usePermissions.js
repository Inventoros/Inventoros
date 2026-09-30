import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { canVisit as canVisitRoute } from '@/lib/routePermissions';

export function usePermissions() {
    const page = usePage();

    const permissions = computed(() => page.props.auth.permissions || []);

    const hasPermission = (permission) => {
        return permissions.value.includes(permission);
    };

    const hasAnyPermission = (permissionArray) => {
        return permissionArray.some(permission => permissions.value.includes(permission));
    };

    const hasAllPermissions = (permissionArray) => {
        return permissionArray.every(permission => permissions.value.includes(permission));
    };

    // Whether the user may open a named page (its route's permission
    // middleware), so links and buttons that would 403 can be hidden.
    const canVisit = (routeName) => canVisitRoute(routeName, permissions.value);

    return {
        permissions,
        hasPermission,
        hasAnyPermission,
        hasAllPermissions,
        canVisit,
    };
}
