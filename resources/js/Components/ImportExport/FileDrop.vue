<script setup>
/**
 * FileDrop is the drag-and-drop / browse picker shared by every import form
 * on the Import/Export page. v-model is the selected File (or null).
 */
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Upload, FileSpreadsheet, X } from 'lucide-vue-next';

const props = defineProps({
    modelValue: {
        type: [Object, null],
        default: null,
    },
    inputId: {
        type: String,
        required: true,
    },
});

const emit = defineEmits(['update:modelValue']);

const { t } = useI18n();
const input = ref(null);
const isDragging = ref(false);

const select = (file) => {
    emit('update:modelValue', file || null);
};

const onChange = (event) => select(event.target.files[0]);

const onDrop = (event) => {
    isDragging.value = false;
    select(event.dataTransfer.files[0]);
};

const clear = () => {
    select(null);
};

// Reset the native input whenever the parent clears the selection (e.g.
// after a successful submit), so choosing the same file again fires change.
watch(() => props.modelValue, (file) => {
    if (!file && input.value) {
        input.value.value = '';
    }
});
</script>

<template>
    <div
        :class="[
            'rounded-lg border-2 border-dashed p-8 text-center transition-colors',
            isDragging ? 'border-brand bg-brand-soft' : 'border-border-subtle bg-surface-canvas',
        ]"
        @drop.prevent="onDrop"
        @dragover.prevent="isDragging = true"
        @dragleave.prevent="isDragging = false"
    >
        <input
            :id="inputId"
            ref="input"
            type="file"
            accept=".csv,.xlsx,.xls"
            class="hidden"
            @change="onChange"
        />

        <div v-if="!modelValue">
            <Upload :size="40" class="mx-auto mb-4 text-text-tertiary" />
            <p class="mb-2 text-sm text-text-secondary">{{ t('importExport.import.dragDrop') }}</p>
            <button
                type="button"
                class="font-semibold text-brand hover:text-brand-hover ds-focus-ring"
                @click="input.click()"
            >
                {{ t('importExport.import.browse') }}
            </button>
            <p class="mt-2 text-sm text-text-tertiary">{{ t('importExport.import.formats') }}</p>
        </div>

        <div v-else class="flex items-center justify-between rounded-lg bg-surface-raised px-4 py-3">
            <div class="flex min-w-0 items-center gap-3">
                <FileSpreadsheet :size="28" class="shrink-0 text-status-success" />
                <div class="min-w-0 text-left">
                    <p class="truncate font-medium text-text-primary">{{ modelValue.name }}</p>
                    <p class="text-sm text-text-tertiary">{{ t('importExport.import.readyToImport') }}</p>
                </div>
            </div>
            <button
                type="button"
                class="rounded-md p-1.5 text-text-tertiary transition-colors hover:bg-surface-overlay hover:text-status-danger ds-focus-ring"
                :aria-label="t('importExport.result.removeFile')"
                @click="clear"
            >
                <X :size="18" />
            </button>
        </div>
    </div>
</template>
