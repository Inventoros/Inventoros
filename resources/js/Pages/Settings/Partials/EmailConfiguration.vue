<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';

const { t } = useI18n();

const props = defineProps({
    emailConfig: Object,
});

const form = useForm({
    provider: props.emailConfig.provider || 'smtp',
    from_address: props.emailConfig.from_address || '',
    from_name: props.emailConfig.from_name || '',
    smtp: {
        host: props.emailConfig.smtp?.host || '',
        port: props.emailConfig.smtp?.port || 587,
        username: props.emailConfig.smtp?.username || '',
        password: props.emailConfig.smtp?.password || '',
        encryption: props.emailConfig.smtp?.encryption || 'tls',
    },
    mailgun: {
        domain: props.emailConfig.mailgun?.domain || '',
        secret: props.emailConfig.mailgun?.secret || '',
    },
    sendgrid: {
        api_key: props.emailConfig.sendgrid?.api_key || '',
    },
});

const testEmailAddress = ref('');
const sendingTest = ref(false);
const testResult = ref(null);

const submit = () => {
    form.post(route('settings.email.update'), {
        preserveScroll: true,
    });
};

const sendTestEmail = () => {
    if (!testEmailAddress.value) return;

    sendingTest.value = true;
    testResult.value = null;

    axios.post(route('settings.email.test'), {
        test_email: testEmailAddress.value
    }).then(() => {
        testResult.value = { ok: true, message: t('settings.email.testSuccess') };
    }).catch(error => {
        testResult.value = { ok: false, message: error.response?.data?.message || error.message };
    }).finally(() => {
        sendingTest.value = false;
    });
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <div class="rounded-lg border border-border-subtle bg-surface-overlay shadow-xs">
        <div class="border-b border-border-subtle px-6 py-5">
            <h3 class="text-lg font-medium text-text-primary">
                {{ t('settings.email.configuration') }}
            </h3>
            <p class="mt-1 text-sm text-text-tertiary">
                {{ t('settings.email.configDescription') }}
                {{ t('settings.email.adminOnly') }}
            </p>
        </div>

        <form @submit.prevent="submit" class="space-y-6 px-6 py-5">
            <!-- Email Provider -->
            <div>
                <label :class="fieldLabel">
                    {{ t('settings.email.provider') }}
                </label>
                <select v-model="form.provider" :class="fieldInput">
                    <option value="smtp">{{ t('settings.email.smtp') }}</option>
                    <option value="phpmail">{{ t('settings.email.phpMail') }}</option>
                    <option value="mailgun">{{ t('settings.email.mailgun') }}</option>
                    <option value="sendgrid">{{ t('settings.email.sendgrid') }}</option>
                </select>
            </div>

            <!-- From Address -->
            <div>
                <label :class="fieldLabel">
                    {{ t('settings.email.fromEmail') }}
                </label>
                <input
                    v-model="form.from_address"
                    type="email"
                    required
                    :class="fieldInput"
                    placeholder="noreply@yourcompany.com"
                />
                <p v-if="form.errors.from_address" :class="fieldError">{{ form.errors.from_address }}</p>
            </div>

            <!-- From Name -->
            <div>
                <label :class="fieldLabel">
                    {{ t('settings.email.fromName') }}
                </label>
                <input
                    v-model="form.from_name"
                    type="text"
                    required
                    :class="fieldInput"
                    placeholder="Your Company Name"
                />
                <p v-if="form.errors.from_name" :class="fieldError">{{ form.errors.from_name }}</p>
            </div>

            <!-- SMTP Settings -->
            <div v-if="form.provider === 'smtp'" class="space-y-4 rounded-lg border border-border-subtle bg-surface-canvas p-4">
                <h4 class="font-medium text-text-primary">{{ t('settings.email.smtpConfig') }}</h4>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label :class="fieldLabel">
                            {{ t('settings.email.host') }}
                        </label>
                        <input
                            v-model="form.smtp.host"
                            type="text"
                            :class="fieldInput"
                            placeholder="smtp.gmail.com"
                        />
                    </div>

                    <div>
                        <label :class="fieldLabel">
                            {{ t('settings.email.port') }}
                        </label>
                        <input
                            v-model.number="form.smtp.port"
                            type="number"
                            :class="fieldInput"
                            placeholder="587"
                        />
                    </div>
                </div>

                <div>
                    <label :class="fieldLabel">
                        {{ t('settings.email.username') }}
                    </label>
                    <input
                        v-model="form.smtp.username"
                        type="text"
                        :class="fieldInput"
                    />
                </div>

                <div>
                    <label :class="fieldLabel">
                        {{ t('settings.email.password') }}
                    </label>
                    <input
                        v-model="form.smtp.password"
                        type="password"
                        :class="fieldInput"
                        :placeholder="t('settings.email.passwordHint')"
                    />
                </div>

                <div>
                    <label :class="fieldLabel">
                        {{ t('settings.email.encryption') }}
                    </label>
                    <select v-model="form.smtp.encryption" :class="fieldInput">
                        <option value="tls">{{ t('settings.email.tls') }}</option>
                        <option value="ssl">{{ t('settings.email.ssl') }}</option>
                        <option value="none">{{ t('settings.email.none') }}</option>
                    </select>
                </div>
            </div>

            <!-- Mailgun Settings -->
            <div v-if="form.provider === 'mailgun'" class="space-y-4 rounded-lg border border-border-subtle bg-surface-canvas p-4">
                <h4 class="font-medium text-text-primary">{{ t('settings.email.mailgunConfig') }}</h4>

                <div>
                    <label :class="fieldLabel">
                        {{ t('settings.email.domain') }}
                    </label>
                    <input
                        v-model="form.mailgun.domain"
                        type="text"
                        :class="fieldInput"
                        placeholder="mg.yourcompany.com"
                    />
                </div>

                <div>
                    <label :class="fieldLabel">
                        {{ t('settings.email.apiSecret') }}
                    </label>
                    <input
                        v-model="form.mailgun.secret"
                        type="password"
                        :class="fieldInput"
                        placeholder="key-..."
                    />
                </div>
            </div>

            <!-- SendGrid Settings -->
            <div v-if="form.provider === 'sendgrid'" class="space-y-4 rounded-lg border border-border-subtle bg-surface-canvas p-4">
                <h4 class="font-medium text-text-primary">{{ t('settings.email.sendgridConfig') }}</h4>

                <div>
                    <label :class="fieldLabel">
                        {{ t('settings.email.apiKey') }}
                    </label>
                    <input
                        v-model="form.sendgrid.api_key"
                        type="password"
                        :class="fieldInput"
                        placeholder="SG...."
                    />
                </div>
            </div>

            <!-- Test Email -->
            <div class="border-t border-border-subtle pt-6">
                <h4 class="mb-3 font-medium text-text-primary">{{ t('settings.email.testEmail') }}</h4>
                <div class="flex gap-2">
                    <input
                        v-model="testEmailAddress"
                        type="email"
                        placeholder="test@example.com"
                        :class="['flex-1', fieldInput]"
                    />
                    <Button
                        type="button"
                        variant="secondary"
                        :loading="sendingTest"
                        :disabled="!testEmailAddress || sendingTest"
                        @click="sendTestEmail"
                    >
                        {{ sendingTest ? t('settings.email.sending') : t('settings.email.sendTest') }}
                    </Button>
                </div>
                <div v-if="testResult" class="mt-3">
                    <Badge :variant="testResult.ok ? 'success' : 'danger'">{{ testResult.message }}</Badge>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="flex justify-end border-t border-border-subtle pt-6">
                <Button
                    type="submit"
                    variant="default"
                    :loading="form.processing"
                    :disabled="form.processing"
                >
                    {{ form.processing ? t('common.saving') : t('settings.email.saveSettings') }}
                </Button>
            </div>
        </form>
    </div>
</template>
