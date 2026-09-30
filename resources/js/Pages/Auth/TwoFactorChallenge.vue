<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

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
        <Head :title="t('auth.twoFactorChallenge.title')" />

        <div class="mb-4 text-sm text-text-secondary">
            <template v-if="!useRecoveryCode">
                {{ t('auth.twoFactorChallenge.codeHint') }}
            </template>
            <template v-else>
                {{ t('auth.twoFactorChallenge.recoveryHint') }}
            </template>
        </div>

        <form @submit.prevent="submit">
            <!-- TOTP Code Input -->
            <div v-if="!useRecoveryCode">
                <InputLabel for="code" :value="t('auth.twoFactorChallenge.code')" />
                <TextInput
                    id="code"
                    v-model="form.code"
                    type="text"
                    class="mt-1 block w-full"
                    required
                    maxlength="6"
                    :placeholder="t('auth.twoFactorChallenge.codePlaceholder')"
                    autocomplete="one-time-code"
                    autofocus
                />
                <InputError class="mt-2" :message="form.errors.code" />
            </div>

            <!-- Recovery Code Input -->
            <div v-else>
                <InputLabel for="recovery_code" :value="t('auth.twoFactorChallenge.recoveryCode')" />
                <TextInput
                    id="recovery_code"
                    v-model="form.recovery_code"
                    type="text"
                    class="mt-1 block w-full"
                    required
                    :placeholder="t('auth.twoFactorChallenge.recoveryPlaceholder')"
                    autofocus
                />
                <InputError class="mt-2" :message="form.errors.code" />
            </div>

            <div class="mt-4 flex items-center justify-between">
                <button
                    type="button"
                    @click="toggleMode"
                    class="text-sm text-text-secondary hover:text-text-primary underline"
                >
                    {{ useRecoveryCode ? t('auth.twoFactorChallenge.useCode') : t('auth.twoFactorChallenge.useRecovery') }}
                </button>

                <PrimaryButton :disabled="form.processing">
                    {{ t('auth.twoFactorChallenge.verify') }}
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
