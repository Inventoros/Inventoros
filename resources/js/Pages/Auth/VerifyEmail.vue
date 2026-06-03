<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    status: {
        type: String,
    },
});

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);
</script>

<template>
    <GuestLayout>
        <Head :title="t('auth.verifyEmail.title')" />

        <div class="mb-4 text-sm text-text-secondary">
            {{ t('auth.verifyEmail.description') }}
        </div>

        <div
            class="mb-4 text-sm font-medium text-status-success"
            v-if="verificationLinkSent"
        >
            {{ t('auth.verifyEmail.linkSent') }}
        </div>

        <form @submit.prevent="submit">
            <div class="mt-4 flex items-center justify-between">
                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    {{ t('auth.verifyEmail.resend') }}
                </PrimaryButton>

                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="rounded-md text-sm text-text-secondary underline hover:text-text-primary ds-focus-ring"
                    >{{ t('auth.verifyEmail.logOut') }}</Link
                >
            </div>
        </form>
    </GuestLayout>
</template>
