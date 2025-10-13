import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

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

    return {
        permissions,
        hasPermission,
        hasAnyPermission,
        hasAllPermissions,
    };
}
