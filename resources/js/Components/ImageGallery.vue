<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
    images: {
        type: Array,
        default: () => []
    },
    productName: {
        type: String,
        default: ''
    }
});

const currentIndex = ref(0);
const showLightbox = ref(false);

const currentImage = computed(() => {
    if (!props.images || props.images.length === 0) return null;
    return props.images[currentIndex.value];
});

const hasMultipleImages = computed(() => props.images && props.images.length > 1);

const selectImage = (index) => {
    currentIndex.value = index;
};

const openLightbox = (index) => {
    currentIndex.value = index;
    showLightbox.value = true;
};

const closeLightbox = () => {
    showLightbox.value = false;
};

const nextImage = () => {
    if (currentIndex.value < props.images.length - 1) {
        currentIndex.value++;
    } else {
        currentIndex.value = 0;
    }
};

const previousImage = () => {
    if (currentIndex.value > 0) {
        currentIndex.value--;
    } else {
        currentIndex.value = props.images.length - 1;
    }
};

const handleKeydown = (e) => {
    if (!showLightbox.value) return;

    if (e.key === 'Escape') {
        closeLightbox();
    } else if (e.key === 'ArrowRight') {
        nextImage();
    } else if (e.key === 'ArrowLeft') {
        previousImage();
    }
};

// Add keyboard listener
if (typeof window !== 'undefined') {
    window.addEventListener('keydown', handleKeydown);
}
</script>

<template>
    <div v-if="images && images.length > 0" class="space-y-4">
        <!-- Main Image Display -->
        <div class="relative aspect-square bg-white dark:bg-dark-bg rounded-lg border-2 border-gray-200 dark:border-dark-border overflow-hidden">
            <img
                :src="currentImage"
                :alt="productName"
                class="w-full h-full object-contain cursor-pointer"
                @click="openLightbox(currentIndex)"
            />

            <!-- Primary Badge -->
            <div v-if="currentIndex === 0" class="absolute top-4 left-4 px-3 py-1 bg-primary-400 text-white text-sm font-semibold rounded shadow-lg">
                Primary
            </div>

            <!-- Zoom Icon -->
            <div class="absolute top-4 right-4 p-2 bg-black/50 text-white rounded-full cursor-pointer hover:bg-black/70 transition" @click="openLightbox(currentIndex)">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" />
                </svg>
            </div>

            <!-- Navigation Arrows (for multiple images) -->
            <button
                v-if="hasMultipleImages"
                @click="previousImage"
                class="absolute left-4 top-1/2 -translate-y-1/2 p-3 bg-black/50 text-white rounded-full hover:bg-black/70 transition"
            >
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
            </button>

            <button
                v-if="hasMultipleImages"
                @click="nextImage"
                class="absolute right-4 top-1/2 -translate-y-1/2 p-3 bg-black/50 text-white rounded-full hover:bg-black/70 transition"
            >
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
            </button>

            <!-- Image Counter -->
            <div v-if="hasMultipleImages" class="absolute bottom-4 left-1/2 -translate-x-1/2 px-4 py-2 bg-black/70 text-white text-sm rounded-full">
                {{ currentIndex + 1 }} / {{ images.length }}
            </div>
        </div>

        <!-- Thumbnail Grid -->
        <div v-if="hasMultipleImages" class="grid grid-cols-5 gap-2">
            <button
                v-for="(image, index) in images"
                :key="index"
                @click="selectImage(index)"
                :class="[
                    'relative aspect-square rounded-lg border-2 overflow-hidden transition cursor-pointer',
                    currentIndex === index
                        ? 'border-primary-400 ring-2 ring-primary-400'
                        : 'border-gray-200 dark:border-dark-border hover:border-primary-400/50'
                ]"
            >
                <img
                    :src="image"
                    :alt="`${productName} - Image ${index + 1}`"
                    class="w-full h-full object-cover"
                />

                <!-- Primary indicator on thumbnail -->
                <div v-if="index === 0" class="absolute top-1 left-1 px-1.5 py-0.5 bg-primary-400 text-white text-xs font-semibold rounded">
                    1°
                </div>
            </button>
        </div>
    </div>

    <!-- No Images Placeholder -->
    <div v-else class="aspect-square bg-gray-100 dark:bg-dark-bg rounded-lg border-2 border-gray-200 dark:border-dark-border flex items-center justify-center">
        <div class="text-center">
            <svg class="w-24 h-24 mx-auto mb-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            <p class="text-gray-500 dark:text-gray-400">No images available</p>
        </div>
    </div>

    <!-- Lightbox Modal -->
    <Teleport to="body">
        <div
            v-if="showLightbox"
            class="fixed inset-0 z-[100] bg-black/95 flex items-center justify-center"
            @click="closeLightbox"
        >
            <!-- Close Button -->
            <button
                @click="closeLightbox"
                class="absolute top-4 right-4 p-3 text-white hover:bg-white/10 rounded-full transition z-10"
            >
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>

            <!-- Image Counter -->
            <div class="absolute top-4 left-1/2 -translate-x-1/2 px-6 py-3 bg-white/10 text-white text-lg rounded-full z-10">
                {{ currentIndex + 1 }} / {{ images.length }}
            </div>

            <!-- Previous Button -->
            <button
                v-if="hasMultipleImages"
                @click.stop="previousImage"
                class="absolute left-4 p-4 text-white hover:bg-white/10 rounded-full transition z-10"
            >
                <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
            </button>

            <!-- Next Button -->
            <button
                v-if="hasMultipleImages"
                @click.stop="nextImage"
                class="absolute right-4 p-4 text-white hover:bg-white/10 rounded-full transition z-10"
            >
                <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
            </button>

            <!-- Main Lightbox Image -->
            <img
                :src="currentImage"
                :alt="`${productName} - Image ${currentIndex + 1}`"
                class="max-w-[90vw] max-h-[90vh] object-contain"
                @click.stop
            />

            <!-- Keyboard Hints -->
            <div class="absolute bottom-4 left-1/2 -translate-x-1/2 flex gap-4 text-white/70 text-sm">
                <span><kbd class="px-2 py-1 bg-white/10 rounded">←</kbd> Previous</span>
                <span><kbd class="px-2 py-1 bg-white/10 rounded">→</kbd> Next</span>
                <span><kbd class="px-2 py-1 bg-white/10 rounded">ESC</kbd> Close</span>
            </div>
        </div>
    </Teleport>
</template>
