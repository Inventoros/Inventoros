<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PortalAuthCard from '@/Components/Portal/PortalAuthCard.vue';
import Button from '@/Components/ui/Button.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

defineProps({
    status: { type: String, default: null },
});

const { t } = useI18n();
const page = usePage();
const orgName = computed(() => page.props.portal?.organization?.name ?? '');

const form = useForm({
    email: '',
    password: '',
});

const submit = () => {
    form.post(route('portal.login.store'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head :title="t('portal.login.title')" />

    <PortalLayout>
        <PortalAuthCard :title="t('portal.login.heading', { org: orgName })" :description="t('portal.login.subtitle')">
            <div v-if="status" class="mb-4 text-sm font-medium text-status-success">
                {{ status }}
            </div>

            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <InputLabel for="email" :value="t('portal.login.email')" />
                    <TextInput
                        id="email"
                        v-model="form.email"
                        type="email"
                        class="mt-1 block w-full"
                        required
                        autofocus
                        autocomplete="username"
                    />
                    <InputError class="mt-2" :message="form.errors.email" />
                </div>

                <div>
                    <InputLabel for="password" :value="t('portal.login.password')" />
                    <TextInput
                        id="password"
                        v-model="form.password"
                        type="password"
                        class="mt-1 block w-full"
                        required
                        autocomplete="current-password"
                    />
                    <InputError class="mt-2" :message="form.errors.password" />
                </div>

                <div class="flex items-center justify-between gap-3 pt-2">
                    <Link
                        :href="route('portal.password.request')"
                        class="rounded-md text-sm text-text-secondary underline hover:text-text-primary ds-focus-ring"
                    >
                        {{ t('portal.login.forgot') }}
                    </Link>
                    <Button type="submit" :loading="form.processing" :disabled="form.processing">
                        {{ t('portal.login.submit') }}
                    </Button>
                </div>
            </form>
        </PortalAuthCard>
    </PortalLayout>
</template>
