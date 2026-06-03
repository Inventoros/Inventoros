<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    currentConfig: Object,
});

// Default port per driver — used when switching drivers and when the
// current value matches the other driver's default (issue #50).
const DEFAULT_PORTS = { mysql: 3306, pgsql: 5432 };

const initialDriver = props.currentConfig.driver === 'pgsql' ? 'pgsql' : 'mysql';

const form = useForm({
    driver: initialDriver,
    host: props.currentConfig.host || 'localhost',
    port: Number(props.currentConfig.port) || DEFAULT_PORTS[initialDriver],
    database: props.currentConfig.database || '',
    username: props.currentConfig.username || '',
    password: '',
});

const testing = ref(false);
const testResult = ref(null);
const installing = ref(false);

// When the user switches driver, invalidate any prior test result and
// nudge the port to the new driver's default if it was the old default.
watch(() => form.driver, (next, prev) => {
    testResult.value = null;
    if (form.port === DEFAULT_PORTS[prev]) {
        form.port = DEFAULT_PORTS[next];
    }
});

const testConnection = async () => {
    testing.value = true;
    testResult.value = null;

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

        const response = await fetch(route('install.database.test'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify(form.data()),
        });

        const data = await response.json();
        testResult.value = data;
    } catch (error) {
        testResult.value = {
            success: false,
            message: 'Failed to test connection: ' + error.message,
        };
    } finally {
        testing.value = false;
    }
};

const install = async () => {
    installing.value = true;

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

        const response = await fetch(route('install.database.install'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify(form.data()),
        });

        const data = await response.json();

        if (data.success) {
            window.location.href = route('install.admin');
        } else {
            testResult.value = data;
        }
    } catch (error) {
        testResult.value = {
            success: false,
            message: 'Installation failed: ' + error.message,
        };
    } finally {
        installing.value = false;
    }
};
</script>

<template>
    <Head :title="t('install.database.title')" />

    <div class="min-h-screen bg-surface-canvas flex items-center justify-center p-4">
        <div class="max-w-2xl w-full">
            <!-- Header -->
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-text-primary mb-2">{{ t('install.database.title') }}</h1>
                <p class="text-text-secondary">{{ t('install.database.subtitle') }}</p>
            </div>

            <!-- Database Form Card -->
            <div class="bg-surface-base border border-border-subtle rounded-lg shadow-sm p-8">
                <!-- Test Result Alert -->
                <div v-if="testResult" class="mb-6">
                    <div
                        v-if="testResult.success"
                        class="bg-status-success-soft border border-status-success/20 rounded-lg p-4"
                    >
                        <div class="flex">
                            <svg class="w-5 h-5 text-status-success" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                            <div class="ml-3">
                                <p class="text-sm font-semibold text-text-primary">{{ testResult.message }}</p>
                            </div>
                        </div>
                    </div>

                    <div
                        v-else
                        class="bg-status-danger-soft border border-status-danger/20 rounded-lg p-4"
                    >
                        <div class="flex">
                            <svg class="w-5 h-5 text-status-danger" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                            </svg>
                            <div class="ml-3">
                                <p class="text-sm font-semibold text-text-primary">{{ testResult.message }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <form @submit.prevent="install" class="space-y-6">
                    <!-- Database Driver (issue #50) -->
                    <div>
                        <label for="driver" class="block text-sm font-medium text-text-secondary mb-2">
                            {{ t('install.database.driver') }}
                        </label>
                        <select
                            id="driver"
                            v-model="form.driver"
                            required
                            class="w-full px-4 py-2 border border-border-subtle rounded-lg bg-surface-canvas text-text-primary focus:ring-2 focus:ring-brand focus:border-transparent"
                        >
                            <option value="mysql">{{ t('install.database.drivers.mysql') }}</option>
                            <option value="pgsql">{{ t('install.database.drivers.pgsql') }}</option>
                        </select>
                    </div>

                    <!-- Database Host -->
                    <div>
                        <label for="host" class="block text-sm font-medium text-text-secondary mb-2">
                            {{ t('install.database.host') }}
                        </label>
                        <input
                            id="host"
                            v-model="form.host"
                            type="text"
                            required
                            class="w-full px-4 py-2 border border-border-subtle rounded-lg bg-surface-canvas text-text-primary placeholder:text-text-tertiary focus:ring-2 focus:ring-brand focus:border-transparent"
                            placeholder="localhost"
                        />
                    </div>

                    <!-- Database Port -->
                    <div>
                        <label for="port" class="block text-sm font-medium text-text-secondary mb-2">
                            {{ t('install.database.port') }}
                        </label>
                        <input
                            id="port"
                            v-model.number="form.port"
                            type="number"
                            required
                            class="w-full px-4 py-2 border border-border-subtle rounded-lg bg-surface-canvas text-text-primary placeholder:text-text-tertiary focus:ring-2 focus:ring-brand focus:border-transparent"
                            :placeholder="String(DEFAULT_PORTS[form.driver])"
                        />
                    </div>

                    <!-- Database Name -->
                    <div>
                        <label for="database" class="block text-sm font-medium text-text-secondary mb-2">
                            {{ t('install.database.name') }}
                        </label>
                        <input
                            id="database"
                            v-model="form.database"
                            type="text"
                            required
                            class="w-full px-4 py-2 border border-border-subtle rounded-lg bg-surface-canvas text-text-primary placeholder:text-text-tertiary focus:ring-2 focus:ring-brand focus:border-transparent"
                            placeholder="inventoros"
                        />
                    </div>

                    <!-- Database Username -->
                    <div>
                        <label for="username" class="block text-sm font-medium text-text-secondary mb-2">
                            {{ t('install.database.username') }}
                        </label>
                        <input
                            id="username"
                            v-model="form.username"
                            type="text"
                            required
                            class="w-full px-4 py-2 border border-border-subtle rounded-lg bg-surface-canvas text-text-primary placeholder:text-text-tertiary focus:ring-2 focus:ring-brand focus:border-transparent"
                            placeholder="root"
                        />
                    </div>

                    <!-- Database Password -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-text-secondary mb-2">
                            {{ t('install.database.password') }}
                        </label>
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            class="w-full px-4 py-2 border border-border-subtle rounded-lg bg-surface-canvas text-text-primary placeholder:text-text-tertiary focus:ring-2 focus:ring-brand focus:border-transparent"
                            :placeholder="t('install.database.passwordHint')"
                        />
                    </div>

                    <!-- Test Connection Button -->
                    <div>
                        <button
                            type="button"
                            @click="testConnection"
                            :disabled="testing"
                            class="w-full px-4 py-2 bg-brand text-brand-foreground font-semibold rounded-lg hover:bg-brand-hover transition disabled:opacity-50"
                        >
                            <span v-if="testing">{{ t('install.database.testing') }}</span>
                            <span v-else>{{ t('install.database.testConnection') }}</span>
                        </button>
                    </div>

                    <!-- Info Box -->
                    <div class="bg-status-info-soft border border-status-info/20 rounded-lg p-4">
                        <div class="flex">
                            <svg class="w-5 h-5 text-status-info mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                            </svg>
                            <div class="ml-3">
                                <p class="text-sm text-text-secondary">
                                    {{ t('install.database.dbExistsHint') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Navigation -->
                    <div class="flex justify-between pt-6 border-t border-border-subtle">
                        <Link
                            :href="route('install.requirements')"
                            class="inline-flex items-center px-4 py-2 text-text-secondary bg-surface-base border border-border-subtle rounded-lg hover:bg-surface-overlay transition"
                        >
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                            {{ t('common.back') }}
                        </Link>

                        <button
                            type="submit"
                            :disabled="installing || !testResult?.success"
                            class="inline-flex items-center px-6 py-2 bg-brand text-brand-foreground font-semibold rounded-lg hover:bg-brand-hover transition disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <span v-if="installing">{{ t('install.database.installing') }}</span>
                            <span v-else>{{ t('install.database.installDatabase') }}</span>
                            <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                            </svg>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
