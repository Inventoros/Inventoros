<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const useRecoveryCode = ref(false);

const form = useForm({
    code: '',
    recovery_code: '',
});

const submit = () => {
    form.post(route('two-factor.challenge.verify'), {
        preserveScroll: true,
        onFinish: () => {
            form.reset();
        },
    });
};

const toggleMode = () => {
    useRecoveryCode.value = !useRecoveryCode.value;
    form.code = '';
    form.recovery_code = '';
    form.clearErrors();
};
</script>

<template>
    <GuestLayout>
        <Head title="Two-Factor Challenge" />

        <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            <template v-if="!useRecoveryCode">
                Please enter the authentication code from your authenticator app.
            </template>
            <template v-else>
                Please enter one of your recovery codes.
            </template>
        </div>

        <form @submit.prevent="submit">
            <!-- TOTP Code Input -->
            <div v-if="!useRecoveryCode">
                <InputLabel for="code" value="Authentication Code" />
                <TextInput
                    id="code"
                    v-model="form.code"
                    type="text"
                    class="mt-1 block w-full"
                    required
                    maxlength="6"
                    placeholder="Enter 6-digit code"
                    autocomplete="one-time-code"
                    autofocus
                />
                <InputError class="mt-2" :message="form.errors.code" />
            </div>

            <!-- Recovery Code Input -->
            <div v-else>
                <InputLabel for="recovery_code" value="Recovery Code" />
                <TextInput
                    id="recovery_code"
                    v-model="form.recovery_code"
                    type="text"
                    class="mt-1 block w-full"
                    required
                    placeholder="Enter recovery code"
                    autofocus
                />
                <InputError class="mt-2" :message="form.errors.code" />
            </div>

            <div class="mt-4 flex items-center justify-between">
                <button
                    type="button"
                    @click="toggleMode"
                    class="text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 underline"
                >
                    {{ useRecoveryCode ? 'Use authentication code' : 'Use a recovery code' }}
                </button>

                <PrimaryButton :disabled="form.processing">
                    Verify
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
