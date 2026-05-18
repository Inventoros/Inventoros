<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue';

// html5-qrcode is ~200 KB minified. It only runs when the user
// actually opens the scanner modal, so the import is deferred to
// startScanner() — keeps it out of the initial page bundle on every
// page that mounts this component (Products/Index,
// PurchaseOrders/Create, PurchaseOrders/Receive,
// StockAdjustments/Create).

const props = defineProps({
    enabled: {
        type: Boolean,
        default: false,
    },
    width: {
        type: Number,
        default: 300,
    },
    height: {
        type: Number,
        default: 200,
    },
});

const emit = defineEmits(['scan', 'error', 'started', 'stopped']);

const scannerElementId = 'barcode-scanner-' + Math.random().toString(36).substring(7);
const html5QrCode = ref(null);
const isScanning = ref(false);
const lastScanTime = ref(0);
const debounceMs = 1500; // Prevent duplicate scans

const startScanner = async () => {
    if (isScanning.value || !props.enabled) return;

    try {
        // Lazy-load html5-qrcode on first scanner start. Vite emits a
        // separate chunk that only downloads when the user opens the
        // camera, not when any page that mounts this component renders.
        const { Html5Qrcode } = await import('html5-qrcode');
        html5QrCode.value = new Html5Qrcode(scannerElementId);

        const config = {
            fps: 10,
            qrbox: { width: Math.min(250, props.width - 50), height: Math.min(150, props.height - 50) },
            aspectRatio: props.width / props.height,
        };

        await html5QrCode.value.start(
            { facingMode: 'environment' },
            config,
            (decodedText) => {
                const now = Date.now();
                if (now - lastScanTime.value > debounceMs) {
                    lastScanTime.value = now;
                    emit('scan', decodedText);
                }
            },
            () => {
                // QR code scanning in progress - ignore errors
            }
        );

        isScanning.value = true;
        emit('started');
    } catch (err) {
        emit('error', err.message || 'Failed to start scanner');
    }
};

const stopScanner = async () => {
    if (!isScanning.value || !html5QrCode.value) return;

    try {
        await html5QrCode.value.stop();
        html5QrCode.value.clear();
        isScanning.value = false;
        emit('stopped');
    } catch (err) {
        console.error('Error stopping scanner:', err);
    }
};

watch(() => props.enabled, (newVal) => {
    if (newVal) {
        startScanner();
    } else {
        stopScanner();
    }
});

onMounted(() => {
    if (props.enabled) {
        startScanner();
    }
});

onUnmounted(() => {
    stopScanner();
});

defineExpose({
    start: startScanner,
    stop: stopScanner,
    isScanning,
});
</script>

<template>
    <div class="barcode-scanner">
        <div
            :id="scannerElementId"
            :style="{ width: width + 'px', height: height + 'px' }"
            class="scanner-container"
        ></div>
    </div>
</template>

<style scoped>
.barcode-scanner {
    display: flex;
    justify-content: center;
    align-items: center;
}

.scanner-container {
    border-radius: 8px;
    overflow: hidden;
    background-color: #000;
}

:deep(#qr-shaded-region) {
    border-color: rgba(59, 130, 246, 0.5) !important;
}

:deep(video) {
    border-radius: 8px;
}
</style>
