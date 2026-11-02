<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>

<template>
    <div class="table-viewport ds-scroll ds-focus-ring" role="region" :aria-label="t('common.results')" tabindex="0" scroll-region>
        <slot />
    </div>
</template>
