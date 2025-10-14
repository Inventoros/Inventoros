<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
    modelValue: {
        type: Array,
        default: () => []
    },
    maxImages: {
        type: Number,
        default: 5
    },
    maxSizeInMB: {
        type: Number,
        default: 5
    }
});

const emit = defineEmits(['update:modelValue']);

const images = ref([...props.modelValue]);
const isDragging = ref(false);
const fileInput = ref(null);
const error = ref('');

const canAddMore = computed(() => images.value.length < props.maxImages);

const handleFiles = (files) => {
    error.value = '';
    
    const filesArray = Array.from(files);
    const remainingSlots = props.maxImages - images.value.length;
    
    if (filesArray.length > remainingSlots) {
        error.value = `You can only add ${remainingSlots} more image(s)`;
        return;
    }

    filesArray.forEach(file => {
        // Validate file type
        if (!file.type.startsWith('image/')) {
            error.value = 'Only image files are allowed';
            return;
        }

        // Validate file size
        if (file.size > props.maxSizeInMB * 1024 * 1024) {
            error.value = `Images must be less than ${props.maxSizeInMB}MB`;
            return;
        }

        // Create preview
        const reader = new FileReader();
        reader.onload = (e) => {
            images.value.push({
                file: file,
                preview: e.target.result,
                name: file.name,
                size: file.size
            });
            emit('update:modelValue', images.value);
        };
        reader.readAsDataURL(file);
    });
};

const handleDrop = (e) => {
    isDragging.value = false;
    handleFiles(e.dataTransfer.files);
};

const handleFileSelect = (e) => {
    handleFiles(e.target.files);
    // Reset input so same file can be selected again
    e.target.value = '';
};

const removeImage = (index) => {
    images.value.splice(index, 1);
    emit('update:modelValue', images.value);
};

const moveImage = (fromIndex, toIndex) => {
    const item = images.value.splice(fromIndex, 1)[0];
    images.value.splice(toIndex, 0, item);
    emit('update:modelValue', images.value);
};

const formatFileSize = (bytes) => {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
};
</script>

<template>
    <div class="space-y-4">
        <!-- Upload Area -->
        <div
            v-if="canAddMore"
            @drop.prevent="handleDrop"
            @dragover.prevent="isDragging = true"
            @dragleave.prevent="isDragging = false"
            :class="[
                'border-2 border-dashed rounded-lg p-6 text-center transition cursor-pointer',
                isDragging
                    ? 'border-primary-400 bg-primary-400/10'
                    : 'border-gray-300 dark:border-dark-border bg-gray-50 dark:bg-dark-bg hover:border-primary-400'
            ]"
            @click="$refs.fileInput.click()"
        >
            <input
                ref="fileInput"
                type="file"
                accept="image/*"
                multiple
                @change="handleFileSelect"
                class="hidden"
            />

            <svg class="w-12 h-12 mx-auto mb-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>

            <p class="text-gray-600 dark:text-gray-300 mb-2">
                Drag and drop images here, or click to browse
            </p>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Max {{ maxImages }} images, up to {{ maxSizeInMB }}MB each ({{ images.length }}/{{ maxImages }})
            </p>
        </div>

        <!-- Error Message -->
        <div v-if="error" class="p-3 bg-red-900/20 border border-red-800 rounded-lg text-red-400 text-sm">
            {{ error }}
        </div>

        <!-- Image Grid -->
        <div v-if="images.length > 0" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            <div
                v-for="(image, index) in images"
                :key="index"
                class="relative group bg-white dark:bg-dark-card rounded-lg border-2 border-gray-200 dark:border-dark-border overflow-hidden"
            >
                <!-- Image Preview -->
                <div class="aspect-square relative">
                    <img
                        :src="image.preview || image.url"
                        :alt="image.name"
                        class="w-full h-full object-cover"
                    />
                    
                    <!-- Primary Badge -->
                    <div v-if="index === 0" class="absolute top-2 left-2 px-2 py-1 bg-primary-400 text-white text-xs font-semibold rounded">
                        Primary
                    </div>

                    <!-- Overlay on hover -->
                    <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-50 transition flex items-center justify-center gap-2 opacity-0 group-hover:opacity-100">
                        <!-- Move Left -->
                        <button
                            v-if="index > 0"
                            @click="moveImage(index, index - 1)"
                            type="button"
                            class="p-2 bg-white dark:bg-dark-card rounded-full hover:bg-gray-100 dark:hover:bg-dark-bg transition"
                            title="Move left"
                        >
                            <svg class="w-5 h-5 text-gray-900 dark:text-gray-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                        </button>

                        <!-- Remove -->
                        <button
                            @click="removeImage(index)"
                            type="button"
                            class="p-2 bg-red-600 rounded-full hover:bg-red-700 transition"
                            title="Remove"
                        >
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>

                        <!-- Move Right -->
                        <button
                            v-if="index < images.length - 1"
                            @click="moveImage(index, index + 1)"
                            type="button"
                            class="p-2 bg-white dark:bg-dark-card rounded-full hover:bg-gray-100 dark:hover:bg-dark-bg transition"
                            title="Move right"
                        >
                            <svg class="w-5 h-5 text-gray-900 dark:text-gray-100" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Image Info -->
                <div class="p-2 bg-gray-50 dark:bg-dark-bg">
                    <p class="text-xs text-gray-600 dark:text-gray-400 truncate" :title="image.name">
                        {{ image.name }}
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-500">
                        {{ formatFileSize(image.size) }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Instructions -->
        <div v-if="images.length === 0" class="text-center p-4 bg-gray-50 dark:bg-dark-bg rounded-lg">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                No images uploaded yet. The first image will be used as the primary product image.
            </p>
        </div>
    </div>
</template>
