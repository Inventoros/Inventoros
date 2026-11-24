<script setup>
import { computed, ref, watch } from 'vue';
import { productInitials } from '@/lib/dashboard';

// The product's first photo, or its initials on a plain tile when it has
// none (or the photo fails to load). Decorative: the name sits beside it.
const props = defineProps({
    product: { type: Object, required: true },
    size: { type: Number, default: 40 },
});

// Initials follow the name the list shows (display_name when the row has one).
const label = computed(() => props.product.display_name ?? props.product.name ?? '');
const failed = ref(false);
const src = computed(() => (failed.value ? null : props.product.image_urls?.[0] || null));
// A new photo (or another product) gets a fresh attempt.
watch(
    () => props.product.image_urls?.[0],
    () => {
        failed.value = false;
    },
);
</script>

<template>
    <span class="product-thumb" :style="{ width: `${size}px`, height: `${size}px` }" aria-hidden="true">
        <img v-if="src" :src="src" alt="" loading="lazy" decoding="async" @error="failed = true" />
        <span v-else class="product-thumb-initials" :style="{ fontSize: `${Math.round(size * 0.34)}px` }">{{ productInitials(label) }}</span>
    </span>
</template>

<style>
.product-thumb {
    display: inline-grid;
    place-items: center;
    flex-shrink: 0;
    overflow: hidden;
    border-radius: 6px;
    border: 1px solid hsl(var(--border-subtle));
    background: hsl(var(--surface-overlay));
}
.product-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.product-thumb-initials {
    color: hsl(var(--text-secondary));
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.02em;
}
@media (forced-colors: active) {
    .product-thumb {
        border-color: CanvasText;
    }
}
</style>
