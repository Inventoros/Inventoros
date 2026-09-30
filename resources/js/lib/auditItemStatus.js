// The status to show for a stock audit line. A line still "pending" when the
// audit is completed or cancelled was never counted, and nothing will count
// it now, so it reads "not counted" rather than "pending".
export function auditItemStatus(itemStatus, auditStatus) {
    if (itemStatus === 'pending' && (auditStatus === 'completed' || auditStatus === 'cancelled')) {
        return 'not_counted';
    }
    return itemStatus;
}
