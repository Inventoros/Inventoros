<script setup>
import { computed } from 'vue';
import { cn } from '@/lib/utils';
defineOptions({ inheritAttrs: false });

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    type: { type: String, default: 'text' },
    placeholder: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    size: {
        type: String,
        default: 'md',
        validator: (s) => ['sm', 'md', 'lg'].includes(s),
    },
});

defineEmits(['update:modelValue']);

const sizeClass = computed(() => ({
    sm: 'h-8 text-xs px-2.5',
    md: 'h-9 text-sm px-3',
    lg: 'h-10 text-sm px-3.5',
}[props.size]));
</script>

<template>
    <div class="relative">
        <span v-if="$slots.prefix" class="absolute start-3 top-1/2 -translate-y-1/2 text-text-tertiary">
            <slot name="prefix" />
        </span>
        <input
            v-bind="$attrs"
            :aria-invalid="invalid || undefined"
            :value="modelValue"
            :type="type"
            :placeholder="placeholder"
            :disabled="disabled"
            @input="$emit('update:modelValue', $event.target.value)"
            :class="cn(
                'w-full rounded-md bg-surface-canvas text-text-primary placeholder:text-text-tertiary',
                'border border-border-subtle',
                'transition-colors duration-100 ds-focus-ring',
                'hover:border-border-strong',
                'disabled:opacity-50 disabled:cursor-not-allowed',
                invalid && 'border-status-danger focus-visible:ring-status-danger/40',
                $slots.prefix && 'ps-9',
                $slots.suffix && 'pe-9',
                sizeClass,
                $attrs.class,
            )"
        />
        <span v-if="$slots.suffix" class="absolute end-3 top-1/2 -translate-y-1/2 text-text-tertiary">
            <slot name="suffix" />
        </span>
    </div>
</template>
