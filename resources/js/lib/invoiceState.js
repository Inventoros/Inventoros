// What the order page's invoice card says about the invoice.
//
// badge: null | 'issued' | 'queued' | 'sent'
// note:  'notIssued' (no number yet) | 'notEmailed' (generated, never emailed)
//        | 'queued' (latest email still waiting) | 'sent' (delivered)
//
// invoice_queued_at is stamped when the email is queued, invoice_sent_at when
// it is delivered; a newer queued stamp means the latest send is still waiting.
export function invoiceState(order = {}) {
    const { invoice_number: number, invoice_queued_at: queuedAt, invoice_sent_at: sentAt } = order;

    const queued = Boolean(queuedAt) && (!sentAt || new Date(sentAt) < new Date(queuedAt));
    if (queued) return { badge: 'queued', note: 'queued' };
    if (sentAt) return { badge: 'sent', note: 'sent' };
    if (number) return { badge: 'issued', note: 'notEmailed' };

    return { badge: null, note: 'notIssued' };
}
