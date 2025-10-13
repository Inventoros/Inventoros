<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    organization: Object,
    user: Object,
});

const activeTab = ref('organization');

// Organization form
const orgForm = useForm({
    name: props.organization.name || '',
    email: props.organization.email || '',
    phone: props.organization.phone || '',
    address: props.organization.address || '',
    city: props.organization.city || '',
    state: props.organization.state || '',
    zip: props.organization.zip || '',
    country: props.organization.country || '',
    currency: props.organization.currency || 'USD',
    timezone: props.organization.timezone || 'UTC',
});

const submitOrganization = () => {
    orgForm.patch(route('settings.organization.update'), {
        preserveScroll: true,
    });
};

// User management
const userForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    is_admin: false,
});

const editingUser = ref(null);
const showUserModal = ref(false);

const openAddUser = () => {
    userForm.reset();
    editingUser.value = null;
    showUserModal.value = true;
};

const openEditUser = (user) => {
    editingUser.value = user;
    userForm.name = user.name;
    userForm.email = user.email;
    userForm.is_admin = user.is_admin;
    userForm.password = '';
    userForm.password_confirmation = '';
    showUserModal.value = true;
};

const submitUser = () => {
    if (editingUser.value) {
        userForm.patch(route('settings.users.update', editingUser.value.id), {
            preserveScroll: true,
            onSuccess: () => {
                showUserModal.value = false;
                userForm.reset();
            },
        });
    } else {
        userForm.post(route('settings.users.store'), {
            preserveScroll: true,
            onSuccess: () => {
                showUserModal.value = false;
                userForm.reset();
            },
        });
    }
};

const deleteUser = (user) => {
    if (confirm(`Are you sure you want to delete ${user.name}?`)) {
        userForm.delete(route('settings.users.destroy', user.id), {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <Head title="Settings" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                Settings
            </h2>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Tabs -->
                <div class="mb-6 border-b border-gray-200 dark:border-dark-border">
                    <nav class="-mb-px flex space-x-8">
                        <button
                            @click="activeTab = 'organization'"
                            :class="[
                                'py-4 px-1 border-b-2 font-medium text-sm transition',
                                activeTab === 'organization'
                                    ? 'border-primary-400 text-primary-400'
                                    : 'border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-600'
                            ]"
                        >
                            Organization
                        </button>
                        <button
                            v-if="user.is_admin"
                            @click="activeTab = 'users'"
                            :class="[
                                'py-4 px-1 border-b-2 font-medium text-sm transition',
                                activeTab === 'users'
                                    ? 'border-primary-400 text-primary-400'
                                    : 'border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-600'
                            ]"
                        >
                            Users
                        </button>
                    </nav>
                </div>

                <!-- Organization Tab -->
                <div v-show="activeTab === 'organization'" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <form @submit.prevent="submitOrganization" class="p-6 space-y-6">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Organization Information</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">Organization Name</label>
                                    <input
                                        v-model="orgForm.name"
                                        type="text"
                                        class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 rounded-md px-4 py-2 focus:outline-none focus:border-primary-400"
                                        :disabled="!user.is_admin"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">Email</label>
                                    <input
                                        v-model="orgForm.email"
                                        type="email"
                                        class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 rounded-md px-4 py-2 focus:outline-none focus:border-primary-400"
                                        :disabled="!user.is_admin"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">Phone</label>
                                    <input
                                        v-model="orgForm.phone"
                                        type="text"
                                        class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 rounded-md px-4 py-2 focus:outline-none focus:border-primary-400"
                                        :disabled="!user.is_admin"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">Address</label>
                                    <input
                                        v-model="orgForm.address"
                                        type="text"
                                        class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 rounded-md px-4 py-2 focus:outline-none focus:border-primary-400"
                                        :disabled="!user.is_admin"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">City</label>
                                    <input
                                        v-model="orgForm.city"
                                        type="text"
                                        class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 rounded-md px-4 py-2 focus:outline-none focus:border-primary-400"
                                        :disabled="!user.is_admin"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">State/Province</label>
                                    <input
                                        v-model="orgForm.state"
                                        type="text"
                                        class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 rounded-md px-4 py-2 focus:outline-none focus:border-primary-400"
                                        :disabled="!user.is_admin"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">ZIP/Postal Code</label>
                                    <input
                                        v-model="orgForm.zip"
                                        type="text"
                                        class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 rounded-md px-4 py-2 focus:outline-none focus:border-primary-400"
                                        :disabled="!user.is_admin"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">Country</label>
                                    <input
                                        v-model="orgForm.country"
                                        type="text"
                                        class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 rounded-md px-4 py-2 focus:outline-none focus:border-primary-400"
                                        :disabled="!user.is_admin"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">Currency</label>
                                    <input
                                        v-model="orgForm.currency"
                                        type="text"
                                        class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 rounded-md px-4 py-2 focus:outline-none focus:border-primary-400"
                                        :disabled="!user.is_admin"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">Timezone</label>
                                    <input
                                        v-model="orgForm.timezone"
                                        type="text"
                                        class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 rounded-md px-4 py-2 focus:outline-none focus:border-primary-400"
                                        :disabled="!user.is_admin"
                                    />
                                </div>
                            </div>
                        </div>

                        <div v-if="user.is_admin" class="flex justify-end">
                            <button
                                type="submit"
                                :disabled="orgForm.processing"
                                class="px-6 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500 transition disabled:opacity-50"
                            >
                                Save Changes
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Users Tab -->
                <div v-show="activeTab === 'users' && user.is_admin">
                    <div class="mb-4 flex justify-end">
                        <button
                            @click="openAddUser"
                            class="px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500 transition"
                        >
                            Add User
                        </button>
                    </div>

                    <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-dark-border">
                            <thead class="bg-gray-50 dark:bg-dark-bg">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Email</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Role</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-dark-border">
                                <tr v-for="orgUser in organization.users" :key="orgUser.id" class="hover:bg-gray-100 dark:hover:bg-dark-bg/50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                        {{ orgUser.name }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        {{ orgUser.email }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span :class="[
                                            'px-2 py-1 text-xs font-semibold rounded-full',
                                            orgUser.is_admin ? 'bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300' : 'bg-gray-900/30 text-gray-400'
                                        ]">
                                            {{ orgUser.is_admin ? 'Admin' : 'User' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                        <button
                                            @click="openEditUser(orgUser)"
                                            class="text-primary-400 hover:text-primary-300 mr-3"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            v-if="orgUser.id !== user.id"
                                            @click="deleteUser(orgUser)"
                                            class="text-red-400 hover:text-red-300"
                                        >
                                            Delete
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- User Modal -->
                <div v-if="showUserModal" class="fixed inset-0 z-50 overflow-y-auto" @click.self="showUserModal = false">
                    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                        <div class="fixed inset-0 transition-opacity bg-gray-900 bg-opacity-75" @click="showUserModal = false"></div>

                        <div class="inline-block align-bottom bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                            <form @submit.prevent="submitUser">
                                <div class="p-6">
                                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                        {{ editingUser ? 'Edit User' : 'Add New User' }}
                                    </h3>
                                    <div class="space-y-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">Name</label>
                                            <input
                                                v-model="userForm.name"
                                                type="text"
                                                required
                                                class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 rounded-md px-4 py-2 focus:outline-none focus:border-primary-400"
                                            />
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">Email</label>
                                            <input
                                                v-model="userForm.email"
                                                type="email"
                                                required
                                                class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 rounded-md px-4 py-2 focus:outline-none focus:border-primary-400"
                                            />
                                        </div>
                                        <div v-if="!editingUser">
                                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">Password</label>
                                            <input
                                                v-model="userForm.password"
                                                type="password"
                                                required
                                                class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 rounded-md px-4 py-2 focus:outline-none focus:border-primary-400"
                                            />
                                        </div>
                                        <div v-if="!editingUser">
                                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">Confirm Password</label>
                                            <input
                                                v-model="userForm.password_confirmation"
                                                type="password"
                                                required
                                                class="w-full bg-gray-50 dark:bg-dark-bg border border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100 rounded-md px-4 py-2 focus:outline-none focus:border-primary-400"
                                            />
                                        </div>
                                        <div class="flex items-center">
                                            <input
                                                v-model="userForm.is_admin"
                                                type="checkbox"
                                                id="is_admin"
                                                class="h-4 w-4 text-primary-400 border-gray-200 dark:border-dark-border rounded focus:ring-primary-400"
                                            />
                                            <label for="is_admin" class="ml-2 block text-sm text-gray-600 dark:text-gray-300">
                                                Administrator
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="bg-gray-50 dark:bg-dark-bg px-6 py-3 flex justify-end gap-3">
                                    <button
                                        type="button"
                                        @click="showUserModal = false"
                                        class="px-4 py-2 bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border rounded-md font-semibold text-xs text-gray-600 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-100 dark:hover:bg-dark-bg transition"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        :disabled="userForm.processing"
                                        class="px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500 transition disabled:opacity-50"
                                    >
                                        {{ editingUser ? 'Update' : 'Create' }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
