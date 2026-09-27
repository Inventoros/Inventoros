<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PortalAuthCard from '@/Components/Portal/PortalAuthCard.vue';
import Button from '@/Components/ui/Button.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    name: { type: String, required: true },
    email: { type: String, required: true },
    // The signed invitation URL; the form posts back to it unchanged.
    acceptUrl: { type: String, required: true },
});

const { t } = useI18n();
const page = usePage();
const orgName = computed(() => page.props.portal?.organization?.name ?? '');

const form = useForm({
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(props.acceptUrl, {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head :title="t('portal.invitation.title')" />

    <PortalLayout>
        <PortalAuthCard
            :title="t('portal.invitation.heading', { name })"
            :description="t('portal.invitation.description', { org: orgName })"
        >
            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <InputLabel for="email" :value="t('portal.invitation.email')" />
                    <TextInput id="email" :model-value="email" type="email" class="mt-1 block w-full" disabled autocomplete="username" />
                </div>

                <div>
                    <InputLabel for="password" :value="t('portal.invitation.password')" />
                    <TextInput
                        id="password"
                        v-model="form.password"
                        type="password"
                        class="mt-1 block w-full"
                        required
                        autofocus
                        autocomplete="new-password"
                    />
                    <InputError class="mt-2" :message="form.errors.password" />
                </div>

                <div>
                    <InputLabel for="password_confirmation" :value="t('portal.invitation.confirm')" />
                    <TextInput
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        class="mt-1 block w-full"
                        required
                        autocomplete="new-password"
                    />
                    <InputError class="mt-2" :message="form.errors.password_confirmation" />
                </div>

                <div class="flex justify-end pt-2">
                    <Button type="submit" :loading="form.processing" :disabled="form.processing">
                        {{ t('portal.invitation.submit') }}
                    </Button>
                </div>
            </form>
        </PortalAuthCard>
    </PortalLayout>
</template>
