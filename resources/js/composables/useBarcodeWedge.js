import { onBeforeUnmount, onMounted, ref } from 'vue';
import axios from 'axios';

/**
 * Keyboard-wedge barcode scanning.
 *
 * USB and Bluetooth scanners act as keyboards: they type the code very fast
 * and finish with Enter. This listens on the window, buffers keystrokes that
 * arrive in quick succession and hands the code to `onScan` when Enter lands.
 *
 * Typing in a text field is left alone, unless that field opts in with a
 * `data-barcode-wedge` attribute, so normal form entry never triggers a scan.
 *
 * @param {(code: string) => void} onScan  called with each scanned code
 * @param {{ minLength?: number, maxGap?: number, enabled?: () => boolean }} options
 */
export function useBarcodeWedge(onScan, options = {}) {
    const minLength = options.minLength ?? 3;
    const maxGap = options.maxGap ?? 50;
    const enabled = options.enabled ?? (() => true);

    let buffer = '';
    let lastKeyAt = 0;

    const isTypingTarget = (el) => {
        if (!el || el === document.body) return false;
        if (el.closest && el.closest('[data-barcode-wedge]')) return false;
        const tag = el.tagName;
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
    };

    const onKeydown = (event) => {
        if (!enabled() || event.ctrlKey || event.altKey || event.metaKey) return;
        if (isTypingTarget(event.target)) {
            buffer = '';
            return;
        }

        const now = Date.now();
        if (now - lastKeyAt > maxGap) {
            buffer = '';
        }
        lastKeyAt = now;

        if (event.key === 'Enter') {
            const code = buffer.trim();
            buffer = '';
            if (code.length >= minLength) {
                event.preventDefault();
                onScan(code);
            }
            return;
        }

        if (event.key.length === 1) {
            buffer += event.key;
        }
    };

    onMounted(() => window.addEventListener('keydown', onKeydown));
    onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
}

/**
 * Look a scanned code up against the organization's products and variants.
 *
 * Resolves to `{ product, variant }` (variant is null for a product code),
 * or null when nothing matches.
 */
export function useBarcodeLookup() {
    const looking = ref(false);

    const lookup = async (code) => {
        looking.value = true;
        try {
            const response = await axios.get(route('barcode.lookup'), { params: { code } });
            if (!response.data?.found) return null;
            return { product: response.data.product, variant: response.data.variant ?? null };
        } catch (err) {
            if (err.response?.status === 404) return null;
            throw err;
        } finally {
            looking.value = false;
        }
    };

    return { lookup, looking };
}
