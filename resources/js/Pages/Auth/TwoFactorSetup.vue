<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ShieldCheck } from '@lucide/vue';

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

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Head title="Two-Factor Authentication Setup" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('settings.account.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('settings.account.index')" class="text-text-tertiary hover:text-text-primary">Settings</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">Two-Factor</span>
            </div>
        </template>

        <PageHeader title="Two-Factor Authentication" description="Add an extra layer of security to your account with an authenticator app.">
            <template v-if="enabled" #actions>
                <Badge variant="success" dot>Enabled</Badge>
            </template>
        </PageHeader>

        <Card class="mt-6 max-w-xl mx-auto">
            <!-- Already enabled -->
            <div v-if="enabled" class="space-y-6">
                <div class="flex items-start gap-3">
                    <ShieldCheck :size="20" class="mt-0.5 shrink-0 text-status-success" />
                    <div>
                        <h3 class="text-sm font-semibold text-text-primary">
                            Two-Factor Authentication is Enabled
                        </h3>
                        <p class="mt-1 text-sm text-text-secondary">
                            Two-factor authentication is currently active on your account.
                            Enter your password to disable it.
                        </p>
                    </div>
                </div>

                <form @submit.prevent="submitDisable" class="space-y-4">
                    <div>
                        <label for="password" :class="fieldLabel">Password</label>
                        <input
                            id="password"
                            v-model="disableForm.password"
                            type="password"
                            :class="fieldInput"
                            required
                            autocomplete="current-password"
                        />
                        <p v-if="disableForm.errors.password" :class="fieldError">{{ disableForm.errors.password }}</p>
                    </div>

                    <Button type="submit" variant="danger" :loading="disableForm.processing" :disabled="disableForm.processing">
                        Disable Two-Factor Authentication
                    </Button>
                </form>
            </div>

            <!-- Setup flow -->
            <div v-else class="space-y-6">
                <div>
                    <h3 class="text-sm font-semibold text-text-primary">
                        Enable Two-Factor Authentication
                    </h3>
                    <p class="mt-1 text-sm text-text-secondary">
                        Scan the QR code below with your authenticator app (Google Authenticator,
                        Authy, etc.), then enter the verification code to enable 2FA.
                    </p>
                </div>

                <!-- QR Code -->
                <div class="flex justify-center">
                    <div class="bg-white p-4 rounded-lg">
                        <div v-html="qrCodeSvg" class="mx-auto"></div>
                    </div>
                </div>

                <!-- Secret key for manual entry -->
                <div>
                    <p class="mb-2 text-sm text-text-secondary">
                        Or enter this key manually:
                    </p>
                    <code class="block select-all rounded-lg bg-surface-sunken p-3 font-mono text-sm text-text-primary">
                        {{ secret }}
                    </code>
                </div>

                <!-- Verification form -->
                <form @submit.prevent="submitEnable" class="space-y-4">
                    <div>
                        <label for="code" :class="fieldLabel">Verification Code</label>
                        <input
                            id="code"
                            v-model="enableForm.code"
                            type="text"
                            :class="fieldInput"
                            required
                            maxlength="6"
                            placeholder="Enter 6-digit code"
                            autocomplete="one-time-code"
                        />
                        <p v-if="enableForm.errors.code" :class="fieldError">{{ enableForm.errors.code }}</p>
                    </div>

                    <Button type="submit" variant="default" :loading="enableForm.processing" :disabled="enableForm.processing">
                        Enable Two-Factor Authentication
                    </Button>
                </form>

                <!-- Recovery Codes -->
                <div class="border-t border-border-subtle pt-6">
                    <Button variant="link" size="sm" @click="showRecoveryCodes = !showRecoveryCodes">
                        {{ showRecoveryCodes ? 'Hide' : 'Show' }} Recovery Codes
                    </Button>

                    <div v-if="showRecoveryCodes" class="mt-4">
                        <p class="mb-3 text-sm text-text-secondary">
                            Save these recovery codes in a safe place. They can be used
                            to access your account if you lose your authenticator device.
                        </p>
                        <div class="rounded-lg bg-surface-sunken p-3 font-mono text-sm text-text-primary">
                            <div class="grid grid-cols-2 gap-2">
                                <code
                                    v-for="code in recoveryCodes"
                                    :key="code"
                                >
                                    {{ code }}
                                </code>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Card>
    </AppLayout>
</template>

