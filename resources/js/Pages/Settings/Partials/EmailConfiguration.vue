<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

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

const submit = () => {
    form.post(route('settings.email.update'), {
        preserveScroll: true,
    });
};

const sendTestEmail = () => {
    if (!testEmailAddress.value) return;

    sendingTest.value = true;

    axios.post(route('settings.email.test'), {
        test_email: testEmailAddress.value
    }).then(() => {
        alert('Test email sent! Check your inbox.');
    }).catch(error => {
        alert('Failed to send test email: ' + (error.response?.data?.message || error.message));
    }).finally(() => {
        sendingTest.value = false;
    });
};
</script>

<template>
    <div class="bg-white dark:bg-dark-card shadow sm:rounded-lg">
        <div class="px-6 py-5 border-b border-gray-200 dark:border-dark-border">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                Email Configuration
            </h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Configure how your organization sends email notifications.
                Only organization admins can change these settings.
            </p>
        </div>

        <form @submit.prevent="submit" class="px-6 py-5 space-y-6">
            <!-- Email Provider -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Email Provider
                </label>
                <select
                    v-model="form.provider"
                    class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-300 dark:border-dark-border text-gray-900 dark:text-gray-100 focus:border-primary-400 focus:ring-primary-400"
                >
                    <option value="smtp">SMTP</option>
                    <option value="phpmail">PHP Mail</option>
                    <option value="mailgun">Mailgun</option>
                    <option value="sendgrid">SendGrid</option>
                </select>
            </div>

            <!-- From Address -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    From Email Address
                </label>
                <input
                    v-model="form.from_address"
                    type="email"
                    required
                    class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-300 dark:border-dark-border text-gray-900 dark:text-gray-100 focus:border-primary-400 focus:ring-primary-400"
                    placeholder="noreply@yourcompany.com"
                />
            </div>

            <!-- From Name -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    From Name
                </label>
                <input
                    v-model="form.from_name"
                    type="text"
                    required
                    class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-300 dark:border-dark-border text-gray-900 dark:text-gray-100 focus:border-primary-400 focus:ring-primary-400"
                    placeholder="Your Company Name"
                />
            </div>

            <!-- SMTP Settings -->
            <div v-if="form.provider === 'smtp'" class="space-y-4 p-4 bg-gray-50 dark:bg-dark-bg rounded-lg border border-gray-200 dark:border-dark-border">
                <h4 class="font-medium text-gray-900 dark:text-gray-100">SMTP Configuration</h4>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Host
                        </label>
                        <input
                            v-model="form.smtp.host"
                            type="text"
                            class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-300 dark:border-dark-border text-gray-900 dark:text-gray-100 focus:border-primary-400 focus:ring-primary-400"
                            placeholder="smtp.gmail.com"
                        />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Port
                        </label>
                        <input
                            v-model.number="form.smtp.port"
                            type="number"
                            class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-300 dark:border-dark-border text-gray-900 dark:text-gray-100 focus:border-primary-400 focus:ring-primary-400"
                            placeholder="587"
                        />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Username
                    </label>
                    <input
                        v-model="form.smtp.username"
                        type="text"
                        class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-300 dark:border-dark-border text-gray-900 dark:text-gray-100 focus:border-primary-400 focus:ring-primary-400"
                    />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Password
                    </label>
                    <input
                        v-model="form.smtp.password"
                        type="password"
                        class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-300 dark:border-dark-border text-gray-900 dark:text-gray-100 focus:border-primary-400 focus:ring-primary-400"
                        placeholder="Leave blank to keep current password"
                    />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Encryption
                    </label>
                    <select
                        v-model="form.smtp.encryption"
                        class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-300 dark:border-dark-border text-gray-900 dark:text-gray-100 focus:border-primary-400 focus:ring-primary-400"
                    >
                        <option value="tls">TLS</option>
                        <option value="ssl">SSL</option>
                        <option value="none">None</option>
                    </select>
                </div>
            </div>

            <!-- Mailgun Settings -->
            <div v-if="form.provider === 'mailgun'" class="space-y-4 p-4 bg-gray-50 dark:bg-dark-bg rounded-lg border border-gray-200 dark:border-dark-border">
                <h4 class="font-medium text-gray-900 dark:text-gray-100">Mailgun Configuration</h4>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Domain
                    </label>
                    <input
                        v-model="form.mailgun.domain"
                        type="text"
                        class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-300 dark:border-dark-border text-gray-900 dark:text-gray-100 focus:border-primary-400 focus:ring-primary-400"
                        placeholder="mg.yourcompany.com"
                    />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        API Secret
                    </label>
                    <input
                        v-model="form.mailgun.secret"
                        type="password"
                        class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-300 dark:border-dark-border text-gray-900 dark:text-gray-100 focus:border-primary-400 focus:ring-primary-400"
                        placeholder="key-..."
                    />
                </div>
            </div>

            <!-- SendGrid Settings -->
            <div v-if="form.provider === 'sendgrid'" class="space-y-4 p-4 bg-gray-50 dark:bg-dark-bg rounded-lg border border-gray-200 dark:border-dark-border">
                <h4 class="font-medium text-gray-900 dark:text-gray-100">SendGrid Configuration</h4>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        API Key
                    </label>
                    <input
                        v-model="form.sendgrid.api_key"
                        type="password"
                        class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-300 dark:border-dark-border text-gray-900 dark:text-gray-100 focus:border-primary-400 focus:ring-primary-400"
                        placeholder="SG...."
                    />
                </div>
            </div>

            <!-- Test Email -->
            <div class="border-t border-gray-200 dark:border-dark-border pt-6">
                <h4 class="font-medium text-gray-900 dark:text-gray-100 mb-3">Test Email Configuration</h4>
                <div class="flex gap-2">
                    <input
                        v-model="testEmailAddress"
                        type="email"
                        placeholder="test@example.com"
                        class="flex-1 rounded-md bg-gray-50 dark:bg-dark-bg border-gray-300 dark:border-dark-border text-gray-900 dark:text-gray-100 focus:border-primary-400 focus:ring-primary-400"
                    />
                    <button
                        type="button"
                        @click="sendTestEmail"
                        :disabled="!testEmailAddress || sendingTest"
                        class="px-4 py-2 bg-gray-200 dark:bg-dark-bg text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-300 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition"
                    >
                        {{ sendingTest ? 'Sending...' : 'Send Test Email' }}
                    </button>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="flex justify-end border-t border-gray-200 dark:border-dark-border pt-6">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="px-4 py-2 bg-primary-400 text-white rounded-md hover:bg-primary-500 disabled:opacity-50 disabled:cursor-not-allowed transition"
                >
                    {{ form.processing ? 'Saving...' : 'Save Email Settings' }}
                </button>
            </div>
        </form>
    </div>
</template>
