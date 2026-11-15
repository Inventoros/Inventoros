<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const page = usePage();
const user = page.props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email,
    current_password: '',
});

// Changing the sign-in email needs the current password.
const emailChanged = computed(() =>
    (form.email || '').trim().toLowerCase() !== (page.props.auth.user?.email || '').toLowerCase(),
);
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-text-primary">
                {{ t('profile.info.title') }}
            </h2>

            <p class="mt-1 text-sm text-text-secondary">
                {{ t('profile.info.description') }}
            </p>
        </header>

        <form
            @submit.prevent="form.patch(route('profile.update'), { onFinish: () => form.reset('current_password') })"
            class="mt-6 space-y-6"
        >
            <div>
                <InputLabel for="name" :value="t('profile.info.name')" />

                <TextInput
                    id="name"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.name"
                    required
                    autofocus
                    autocomplete="name"
                />

                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="email" :value="t('profile.info.email')" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div v-if="emailChanged || form.errors.current_password">
                <InputLabel for="profile_current_password" :value="t('profile.password.currentPassword')" />

                <TextInput
                    id="profile_current_password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.current_password"
                    required
                    autocomplete="current-password"
                />

                <InputError class="mt-2" :message="form.errors.current_password" />
            </div>

            <div v-if="mustVerifyEmail && user.email_verified_at === null">
                <p class="mt-2 text-sm text-gray-800 dark:text-gray-200">
                    {{ t('profile.info.unverified') }}
                    <Link
                        :href="route('verification.send')"
                        method="post"
                        as="button"
                        class="rounded-md text-sm text-text-secondary underline hover:text-text-primary ds-focus-ring"
                    >
                        {{ t('profile.info.resendVerification') }}
                    </Link>
                </p>

                <div
                    v-show="status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600 dark:text-green-400"
                >
                    {{ t('profile.info.verificationSent') }}
                </div>
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">{{ t('common.save') }}</PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-if="form.recentlySuccessful"
                        class="text-sm text-text-secondary"
                    >
                        {{ t('common.saved') }}
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
