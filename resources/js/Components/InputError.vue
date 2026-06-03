<script setup>
defineProps({
    message: {
        type: String,
    },
});
</script>

<template>
    <div v-show="message">
        <p class="text-sm text-status-danger">
            {{ message }}
        </p>
    </div>
</template>
