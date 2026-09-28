<script setup>
const year = new Date().getFullYear();
</script>

<template>
    <footer class="text-xs text-text-tertiary">
        <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span>&copy; {{ year }} Inventoros</span>
            <span aria-hidden="true">&middot;</span>
            <a href="https://inventoros.com" target="_blank" rel="noopener noreferrer" class="hover:text-text-secondary">inventoros.com</a>
            <span aria-hidden="true">&middot;</span>
            <i18n-t keypath="legal.licensedUnder" tag="span">
                <template #license>
                    <a href="https://www.gnu.org/licenses/agpl-3.0.html" target="_blank" rel="noopener noreferrer" class="underline hover:text-text-secondary">GNU AGPL v3</a>
                </template>
            </i18n-t>
            <span aria-hidden="true">&middot;</span>
            <a href="https://github.com/Inventoros/Inventoros" target="_blank" rel="noopener noreferrer" class="hover:text-text-secondary">{{ $t('legal.sourceCode') }}</a>
        </p>
    </footer>
</template>
