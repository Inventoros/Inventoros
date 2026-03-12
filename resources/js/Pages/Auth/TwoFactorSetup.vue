<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    qrCodeSvg: String,
    secret: String,
    recoveryCodes: Array,
    enabled: Boolean,
});

const showRecoveryCodes = ref(false);

const enableForm = useForm({
    code: '',
});

const disableForm = useForm({
    password: '',
});

const submitEnable = () => {
    enableForm.post(route('two-factor.enable'), {
        preserveScroll: true,
        onSuccess: () => {
            enableForm.reset();
        },
    });
};

const submitDisable = () => {
    disableForm.post(route('two-factor.disable'), {
        preserveScroll: true,
        onSuccess: () => {
            disableForm.reset();
        },
    });
};
</script>

<template>
    <Head title="Two-Factor Authentication Setup" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-900 dark:text-gray-100 leading-tight">
                Two-Factor Authentication
            </h2>
        </template>

        <div class="py-12 bg-gray-50 dark:bg-dark-bg min-h-screen">
            <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-dark-card border border-gray-200 dark:border-dark-border overflow-hidden shadow-lg sm:rounded-lg">
                    <div class="p-6 space-y-6">
                        <!-- Already enabled -->
                        <div v-if="enabled">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                Two-Factor Authentication is Enabled
                            </h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">
                                Two-factor authentication is currently active on your account.
                                Enter your password to disable it.
                            </p>

                            <form @submit.prevent="submitDisable" class="space-y-4">
                                <div>
                                    <InputLabel for="password" value="Password" />
                                    <TextInput
                                        id="password"
                                        v-model="disableForm.password"
                                        type="password"
                                        class="mt-1 block w-full"
                                        required
                                        autocomplete="current-password"
                                    />
                                    <InputError class="mt-2" :message="disableForm.errors.password" />
                                </div>

                                <PrimaryButton :disabled="disableForm.processing" class="bg-red-600 hover:bg-red-700">
                                    Disable Two-Factor Authentication
                                </PrimaryButton>
                            </form>
                        </div>

                        <!-- Setup flow -->
                        <div v-else>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                                Enable Two-Factor Authentication
                            </h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">
                                Scan the QR code below with your authenticator app (Google Authenticator,
                                Authy, etc.), then enter the verification code to enable 2FA.
                            </p>

                            <!-- QR Code -->
                            <div class="flex justify-center mb-6 bg-white p-4 rounded-lg inline-block mx-auto">
                                <div v-html="qrCodeSvg" class="mx-auto"></div>
                            </div>

                            <!-- Secret key for manual entry -->
                            <div class="mb-6">
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                                    Or enter this key manually:
                                </p>
                                <code class="block bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-4 py-2 rounded text-sm font-mono select-all">
                                    {{ secret }}
                                </code>
                            </div>

                            <!-- Verification form -->
                            <form @submit.prevent="submitEnable" class="space-y-4">
                                <div>
                                    <InputLabel for="code" value="Verification Code" />
                                    <TextInput
                                        id="code"
                                        v-model="enableForm.code"
                                        type="text"
                                        class="mt-1 block w-full"
                                        required
                                        maxlength="6"
                                        placeholder="Enter 6-digit code"
                                        autocomplete="one-time-code"
                                    />
                                    <InputError class="mt-2" :message="enableForm.errors.code" />
                                </div>

                                <PrimaryButton :disabled="enableForm.processing">
                                    Enable Two-Factor Authentication
                                </PrimaryButton>
                            </form>

                            <!-- Recovery Codes -->
                            <div class="mt-8 pt-6 border-t border-gray-200 dark:border-dark-border">
                                <button
                                    @click="showRecoveryCodes = !showRecoveryCodes"
                                    class="text-sm text-primary-400 hover:text-primary-300 font-medium"
                                >
                                    {{ showRecoveryCodes ? 'Hide' : 'Show' }} Recovery Codes
                                </button>

                                <div v-if="showRecoveryCodes" class="mt-4">
                                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                                        Save these recovery codes in a safe place. They can be used
                                        to access your account if you lose your authenticator device.
                                    </p>
                                    <div class="bg-gray-100 dark:bg-gray-800 rounded-lg p-4">
                                        <div class="grid grid-cols-2 gap-2">
                                            <code
                                                v-for="code in recoveryCodes"
                                                :key="code"
                                                class="text-sm font-mono text-gray-900 dark:text-gray-100"
                                            >
                                                {{ code }}
                                            </code>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
