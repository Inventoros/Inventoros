import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { AuditCountDraftStore, auditDraftKey } from '../lib/auditCountDrafts';

export function useAuditCountDrafts(audit) {
    const page = usePage();
    const scopeKey = computed(() => auditDraftKey(page.props.auth?.user?.id, audit().organization_id, audit().id));
    const drafts = ref({});
    const storageAvailable = ref(true);
    let store;

    const refresh = () => {
        drafts.value = { ...store.drafts };
        storageAvailable.value = store.available;
    };

    watch(scopeKey, (key) => {
        let storage = null;
        try { storage = window.sessionStorage; } catch { /* Storage can be disabled. */ }
        store = new AuditCountDraftStore(storage, key);
        refresh();
    }, { immediate: true });

    const remember = (...args) => {
        const remembered = store.remember(...args);
        refresh();
        return remembered;
    };

    const forget = (...args) => { store.forget(...args); refresh(); };
    const clear = () => { store.clear(); refresh(); };
    const count = computed(() => Object.keys(drafts.value).length);
    const warnBeforeUnload = (event) => {
        if (count.value === 0) return;
        event.preventDefault();
        event.returnValue = '';
    };
    onMounted(() => window.addEventListener('beforeunload', warnBeforeUnload));
    onBeforeUnmount(() => window.removeEventListener('beforeunload', warnBeforeUnload));

    return { drafts, count, storageAvailable, scopeKey, remember, forget, clear };
}
