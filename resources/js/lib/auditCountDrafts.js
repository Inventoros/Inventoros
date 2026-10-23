// Only unfinished count inputs live here. The audit catalogue, authentication
// data and server responses are never cached. sessionStorage keeps drafts in
// this tab, namespaced to the user, organization and audit.
export function auditDraftKey(userId, organizationId, auditId) {
    const ids = [userId, organizationId, auditId];
    if (!ids.every((id) => Number.isSafeInteger(id) && id > 0)) return null;
    return `inventoros:audit-count-drafts:v1:${ids.join(':')}`;
}

const validRevision = (value) => typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);

function validDraft(draft) {
    return draft && Number.isSafeInteger(draft.itemId) && draft.itemId > 0
        && validRevision(draft.expectedRevision)
        && typeof draft.quantity === 'string' && draft.quantity.length <= 32
        && typeof draft.notes === 'string' && draft.notes.length <= 500;
}

export function countDraftPayload(draft) {
    if (!validDraft(draft) || !/^\d+$/.test(draft.quantity)) return null;
    const quantity = Number(draft.quantity);
    if (!Number.isSafeInteger(quantity) || quantity > 2147483647) return null;
    return { counted_quantity: quantity, notes: draft.notes || null, expected_revision: draft.expectedRevision };
}

export class AuditCountDraftStore {
    constructor(storage, key) {
        this.storage = storage;
        this.key = key;
        this.drafts = {};
        this.available = Boolean(storage && key);
        if (!this.available) return;
        try {
            const raw = storage.getItem(key);
            if (!raw) return;
            const data = JSON.parse(raw);
            if (data?.version !== 1 || !Array.isArray(data.drafts)) return;
            for (const draft of data.drafts) {
                if (validDraft(draft)) this.drafts[draft.itemId] = { ...draft };
            }
        } catch {
            this.available = false;
        }
    }

    remember(item, quantity, notes, expectedRevision = null) {
        const draft = {
            itemId: item.id,
            quantity: String(quantity ?? ''),
            notes: notes ?? '',
            // Editing a restored draft must not quietly adopt a newer server
            // count. Only an explicit review may replace its original token.
            expectedRevision: expectedRevision ?? this.drafts[item.id]?.expectedRevision ?? item.count_revision,
        };
        if (!validDraft(draft)) return false;
        this.drafts[item.id] = draft;
        this.persist();
        return true;
    }

    forget(itemId, sentDraft = null) {
        const current = this.drafts[itemId];
        if (sentDraft && current && (current.quantity !== sentDraft.quantity
            || current.notes !== sentDraft.notes || current.expectedRevision !== sentDraft.expectedRevision)) return;
        delete this.drafts[itemId];
        this.persist();
    }

    clear() {
        this.drafts = {};
        this.persist();
    }

    persist() {
        try {
            if (!this.storage || !this.key) throw new Error('Storage unavailable');
            if (Object.keys(this.drafts).length === 0) this.storage.removeItem(this.key);
            else this.storage.setItem(this.key, JSON.stringify({ version: 1, drafts: Object.values(this.drafts) }));
            this.available = true;
        } catch {
            // Keep the in-memory draft and let the UI tell the user that a
            // refresh would lose it. Failed storage must not break counting.
            this.available = false;
        }
    }
}
