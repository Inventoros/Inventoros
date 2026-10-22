<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import axios from 'axios';

const props = defineProps({ disks: { type: Array, required: true } });
const { t } = useI18n();
const results = ref(Object.fromEntries(props.disks.map(disk => [disk.name, disk.last_check])));
const checking = ref(null);
const requestError = ref(null);

async function check(disk) {
    checking.value = disk;
    requestError.value = null;
    results.value[disk] = null;
    try {
        const response = await axios.post(route('settings.storage.check'), { disk });
        results.value[disk] = response.data;
    } catch {
        requestError.value = disk;
    } finally {
        checking.value = null;
    }
}
</script>

<template>
    <Head :title="t('storage.title')" />
    <AppLayout>
        <template #header>
            <Link :href="route('settings.index')" class="text-sm text-text-secondary hover:text-text-primary">{{ t('settings.title') }}</Link>
        </template>
        <PageHeader :title="t('storage.title')" :description="t('storage.description')" />
        <Card class="mt-6">
            <p class="text-sm text-text-secondary">{{ t('storage.configure') }}</p>
            <p class="mt-2 text-sm text-text-secondary">{{ t('storage.existingFiles') }}</p>
            <p class="mt-2 text-sm text-text-secondary">{{ t('storage.probe') }}</p>
        </Card>
        <div class="mt-4 space-y-4">
            <Card v-for="disk in disks" :key="disk.name">
                <h2 class="text-base font-semibold text-text-primary">{{ disk.name }}</h2>
                <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-text-tertiary">{{ t('storage.driver') }}</dt><dd class="text-text-primary">{{ disk.driver || t('storage.notConfigured') }}</dd></div>
                    <div><dt class="text-text-tertiary">{{ t('storage.usedFor') }}</dt><dd class="text-text-primary">{{ disk.purposes.map(purpose => t(`storage.purposes.${purpose}`)).join(', ') }}</dd></div>
                    <div v-if="disk.bucket"><dt class="text-text-tertiary">{{ t('storage.bucket') }}</dt><dd class="text-text-primary">{{ disk.bucket }}</dd></div>
                    <div v-if="disk.region"><dt class="text-text-tertiary">{{ t('storage.region') }}</dt><dd class="text-text-primary">{{ disk.region }}</dd></div>
                </dl>
                <div class="mt-4" aria-live="polite" role="status">
                    <p v-if="results[disk.name]?.success" class="text-sm text-status-success">{{ t('storage.connected') }}</p>
                    <p v-else-if="results[disk.name]" class="text-sm text-status-danger">{{ t('storage.failed', { step: t(`storage.steps.${results[disk.name].failed_step}`) }) }}</p>
                    <p v-else class="text-sm text-text-tertiary">{{ t('storage.unchecked') }}</p>
                    <p v-if="results[disk.name]" class="mt-1 text-xs text-text-tertiary">{{ t('storage.checkedAt', { time: results[disk.name].checked_at }) }}</p>
                    <p v-if="results[disk.name]?.cleanup_failed" class="mt-1 text-sm text-status-danger">{{ t('storage.cleanupFailed') }}</p>
                    <p v-if="requestError === disk.name" class="mt-1 text-sm text-status-danger">{{ t('storage.requestFailed') }}</p>
                </div>
                <Button class="mt-4" :disabled="checking !== null" @click="check(disk.name)">{{ checking === disk.name ? t('storage.checking') : t('storage.check') }}</Button>
            </Card>
        </div>
    </AppLayout>
</template>
