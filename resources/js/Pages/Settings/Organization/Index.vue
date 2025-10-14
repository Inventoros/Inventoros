<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    organization: Object,
    user: Object,
});

const activeTab = ref('general');

// General settings form
const generalForm = useForm({
    name: props.organization.name || '',
    email: props.organization.email || '',
    phone: props.organization.phone || '',
    address: props.organization.address || '',
    city: props.organization.city || '',
    state: props.organization.state || '',
    zip: props.organization.zip || '',
    country: props.organization.country || '',
});

const submitGeneral = () => {
    generalForm.patch(route('settings.organization.update.general'), {
        preserveScroll: true,
    });
};

// Regional settings form
const regionalForm = useForm({
    currency: props.organization.currency || 'USD',
    timezone: props.organization.timezone || 'UTC',
    date_format: props.organization.date_format || 'Y-m-d',
    time_format: props.organization.time_format || 'H:i',
});

const submitRegional = () => {
    regionalForm.patch(route('settings.organization.update.regional'), {
        preserveScroll: true,
    });
};

const isAdmin = props.user.is_admin;
</script>

<template>
    <Head title="Organization Settings" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                Organization Settings
            </h2>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Tabs -->
                <div class="mb-6 border-b border-gray-200 dark:border-dark-border">
                    <nav class="-mb-px flex space-x-8">
                        <button
                            @click="activeTab = 'general'"
                            :class="[
                                'py-4 px-1 border-b-2 font-medium text-sm transition',
                                activeTab === 'general'
                                    ? 'border-primary-400 text-primary-400'
                                    : 'border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-600'
                            ]"
                        >
                            General Information
                        </button>
                        <button
                            @click="activeTab = 'regional'"
                            :class="[
                                'py-4 px-1 border-b-2 font-medium text-sm transition',
                                activeTab === 'regional'
                                    ? 'border-primary-400 text-primary-400'
                                    : 'border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-600'
                            ]"
                        >
                            Regional Settings
                        </button>
                        <button
                            v-if="isAdmin"
                            @click="activeTab = 'users'"
                            :class="[
                                'py-4 px-1 border-b-2 font-medium text-sm transition',
                                activeTab === 'users'
                                    ? 'border-primary-400 text-primary-400'
                                    : 'border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-600'
                            ]"
                        >
                            User Management
                        </button>
                    </nav>
                </div>

                <!-- General Information Tab -->
                <div v-show="activeTab === 'general'" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <form @submit.prevent="submitGeneral" class="p-6 space-y-6">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Organization Information</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <InputLabel for="name" value="Organization Name" />
                                    <TextInput
                                        id="name"
                                        v-model="generalForm.name"
                                        type="text"
                                        class="mt-1 block w-full"
                                        :disabled="!isAdmin"
                                    />
                                    <InputError class="mt-2" :message="generalForm.errors.name" />
                                </div>
                                <div>
                                    <InputLabel for="email" value="Email" />
                                    <TextInput
                                        id="email"
                                        v-model="generalForm.email"
                                        type="email"
                                        class="mt-1 block w-full"
                                        :disabled="!isAdmin"
                                    />
                                    <InputError class="mt-2" :message="generalForm.errors.email" />
                                </div>
                                <div>
                                    <InputLabel for="phone" value="Phone" />
                                    <TextInput
                                        id="phone"
                                        v-model="generalForm.phone"
                                        type="text"
                                        class="mt-1 block w-full"
                                        :disabled="!isAdmin"
                                    />
                                    <InputError class="mt-2" :message="generalForm.errors.phone" />
                                </div>
                                <div>
                                    <InputLabel for="address" value="Address" />
                                    <TextInput
                                        id="address"
                                        v-model="generalForm.address"
                                        type="text"
                                        class="mt-1 block w-full"
                                        :disabled="!isAdmin"
                                    />
                                    <InputError class="mt-2" :message="generalForm.errors.address" />
                                </div>
                                <div>
                                    <InputLabel for="city" value="City" />
                                    <TextInput
                                        id="city"
                                        v-model="generalForm.city"
                                        type="text"
                                        class="mt-1 block w-full"
                                        :disabled="!isAdmin"
                                    />
                                    <InputError class="mt-2" :message="generalForm.errors.city" />
                                </div>
                                <div>
                                    <InputLabel for="state" value="State/Province" />
                                    <TextInput
                                        id="state"
                                        v-model="generalForm.state"
                                        type="text"
                                        class="mt-1 block w-full"
                                        :disabled="!isAdmin"
                                    />
                                    <InputError class="mt-2" :message="generalForm.errors.state" />
                                </div>
                                <div>
                                    <InputLabel for="zip" value="ZIP/Postal Code" />
                                    <TextInput
                                        id="zip"
                                        v-model="generalForm.zip"
                                        type="text"
                                        class="mt-1 block w-full"
                                        :disabled="!isAdmin"
                                    />
                                    <InputError class="mt-2" :message="generalForm.errors.zip" />
                                </div>
                                <div>
                                    <InputLabel for="country" value="Country" />
                                    <TextInput
                                        id="country"
                                        v-model="generalForm.country"
                                        type="text"
                                        class="mt-1 block w-full"
                                        :disabled="!isAdmin"
                                    />
                                    <InputError class="mt-2" :message="generalForm.errors.country" />
                                </div>
                            </div>
                        </div>

                        <div v-if="isAdmin" class="flex justify-end">
                            <PrimaryButton :disabled="generalForm.processing">
                                Save Changes
                            </PrimaryButton>
                        </div>
                    </form>
                </div>

                <!-- Regional Settings Tab -->
                <div v-show="activeTab === 'regional'" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <form @submit.prevent="submitRegional" class="p-6 space-y-6">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Regional Settings</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <InputLabel for="currency" value="Currency" />
                                    <TextInput
                                        id="currency"
                                        v-model="regionalForm.currency"
                                        type="text"
                                        class="mt-1 block w-full"
                                        :disabled="!isAdmin"
                                        placeholder="USD"
                                    />
                                    <InputError class="mt-2" :message="regionalForm.errors.currency" />
                                </div>
                                <div>
                                    <InputLabel for="timezone" value="Timezone" />
                                    <TextInput
                                        id="timezone"
                                        v-model="regionalForm.timezone"
                                        type="text"
                                        class="mt-1 block w-full"
                                        :disabled="!isAdmin"
                                        placeholder="UTC"
                                    />
                                    <InputError class="mt-2" :message="regionalForm.errors.timezone" />
                                </div>
                                <div>
                                    <InputLabel for="date_format" value="Date Format" />
                                    <TextInput
                                        id="date_format"
                                        v-model="regionalForm.date_format"
                                        type="text"
                                        class="mt-1 block w-full"
                                        :disabled="!isAdmin"
                                        placeholder="Y-m-d"
                                    />
                                    <InputError class="mt-2" :message="regionalForm.errors.date_format" />
                                </div>
                                <div>
                                    <InputLabel for="time_format" value="Time Format" />
                                    <TextInput
                                        id="time_format"
                                        v-model="regionalForm.time_format"
                                        type="text"
                                        class="mt-1 block w-full"
                                        :disabled="!isAdmin"
                                        placeholder="H:i"
                                    />
                                    <InputError class="mt-2" :message="regionalForm.errors.time_format" />
                                </div>
                            </div>
                        </div>

                        <div v-if="isAdmin" class="flex justify-end">
                            <PrimaryButton :disabled="regionalForm.processing">
                                Save Changes
                            </PrimaryButton>
                        </div>
                    </form>
                </div>

                <!-- User Management Tab -->
                <div v-show="activeTab === 'users' && isAdmin" class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg p-6">
                    <div class="text-center py-12">
                        <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                        </svg>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">User Management</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                            User management functionality is available in the Users section.
                        </p>
                        <a
                            :href="route('users.index')"
                            class="inline-flex items-center px-4 py-2 bg-primary-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-primary-500 transition"
                        >
                            Go to User Management
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
