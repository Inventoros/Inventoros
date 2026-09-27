<script setup>
import Button from '@/Components/ui/Button.vue';
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { X, Paperclip } from 'lucide-vue-next';

/**
 * Modal for emailing a document (purchase order, invoice) with the recipient
 * prefilled from the record and optional CC and message.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    action: { type: String, required: true },
    defaultTo: { type: String, default: '' },
    attachmentName: { type: String, default: '' },
    submitLabel: { type: String, default: '' },
});

const emit = defineEmits(['close']);

const { t } = useI18n();

const form = useForm({
    to: props.defaultTo || '',
    cc: '',
    message: '',
});

// Re-prefill every time the modal opens so a cancelled edit does not linger.
watch(
    () => props.show,
    (open) => {
        if (open) {
            form.reset();
            form.clearErrors();
            form.to = props.defaultTo || '';
        }
    },
);

const close = () => {
    if (!form.processing) {
        emit('close');
    }
};

const submit = () => {
    form.post(props.action, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
};

const inputClass =
    'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary ' +
    'placeholder:text-text-tertiary transition-colors hover:border-border-strong ds-focus-ring';
const labelClass = 'mb-1 block text-sm font-medium text-text-secondary';
const errorClass = 'mt-1 text-xs text-status-danger';
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center" @click="close">
            <div class="fixed inset-0 bg-black/50"></div>

            <form
                class="relative mx-4 w-full max-w-lg rounded-xl border border-border-subtle bg-surface-raised p-6 shadow-lg"
                @click.stop
                @submit.prevent="submit"
            >
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-base font-semibold text-text-primary">{{ title }}</h3>
                    <button
                        type="button"
                        class="text-text-tertiary transition-colors hover:text-text-primary ds-focus-ring"
                        :aria-label="t('common.cancel')"
                        @click="close"
                    >
                        <X :size="18" />
                    </button>
                </div>

                <p v-if="description" class="mb-4 text-sm text-text-secondary">{{ description }}</p>

                <div class="space-y-4">
                    <div>
                        <label for="send-document-to" :class="labelClass">{{ t('documentEmail.to') }}</label>
                        <input
                            id="send-document-to"
                            v-model="form.to"
                            type="email"
                            required
                            maxlength="255"
                            :class="[inputClass, form.errors.to && 'border-status-danger']"
                        />
                        <p v-if="form.errors.to" :class="errorClass">{{ form.errors.to }}</p>
                        <p v-else-if="!defaultTo" class="mt-1 text-xs text-status-warning">{{ t('documentEmail.noRecipient') }}</p>
                    </div>

                    <div>
                        <label for="send-document-cc" :class="labelClass">{{ t('documentEmail.cc') }}</label>
                        <input
                            id="send-document-cc"
                            v-model="form.cc"
                            type="text"
                            maxlength="1000"
                            :class="[inputClass, form.errors.cc && 'border-status-danger']"
                        />
                        <p v-if="form.errors.cc" :class="errorClass">{{ form.errors.cc }}</p>
                        <p v-else class="mt-1 text-xs text-text-tertiary">{{ t('documentEmail.ccHint') }}</p>
                    </div>

                    <div>
                        <label for="send-document-message" :class="labelClass">{{ t('documentEmail.message') }}</label>
                        <textarea
                            id="send-document-message"
                            v-model="form.message"
                            rows="4"
                            maxlength="2000"
                            class="w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring"
                            :placeholder="t('documentEmail.messagePlaceholder')"
                        ></textarea>
                        <p v-if="form.errors.message" :class="errorClass">{{ form.errors.message }}</p>
                    </div>

                    <p v-if="attachmentName" class="flex items-center gap-1.5 text-xs text-text-tertiary">
                        <Paperclip :size="14" />
                        {{ t('documentEmail.attachmentNote', { file: attachmentName }) }}
                    </p>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <Button variant="secondary" :disabled="form.processing" @click="close">
                        {{ t('common.cancel') }}
                    </Button>
                    <Button type="submit" :loading="form.processing" :disabled="form.processing || !form.to">
                        {{ form.processing ? t('documentEmail.sending') : (submitLabel || t('documentEmail.send')) }}
                    </Button>
                </div>
            </form>
        </div>
    </Teleport>
</template>
