<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PortalAuthCard from '@/Components/Portal/PortalAuthCard.vue';
import Button from '@/Components/ui/Button.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

defineProps({
    status: { type: String, default: null },
});

const { t } = useI18n();

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('portal.password.email'));
};
</script>

<template>
    <Head :title="t('portal.forgot.title')" />

    <PortalLayout>
        <PortalAuthCard :title="t('portal.forgot.title')" :description="t('portal.forgot.description')">
            <div v-if="status" class="mb-4 text-sm font-medium text-status-success">
                {{ status }}
            </div>

            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <InputLabel for="email" :value="t('portal.forgot.email')" />
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

                <div class="flex items-center justify-between gap-3 pt-2">
                    <Link
                        :href="route('portal.login')"
                        class="rounded-md text-sm text-text-secondary underline hover:text-text-primary ds-focus-ring"
                    >
                        {{ t('portal.forgot.back') }}
                    </Link>
                    <Button type="submit" :loading="form.processing" :disabled="form.processing">
                        {{ t('portal.forgot.submit') }}
                    </Button>
                </div>
            </form>
        </PortalAuthCard>
    </PortalLayout>
</template>
