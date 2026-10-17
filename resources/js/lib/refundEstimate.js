// Goods-only return estimates follow the server's cumulative, recorded-amount
// calculation. All arithmetic stays in integer cents, including half-cent ties.
function cents(value) {
    const match = String(value ?? '').trim().match(/^(\d+)(?:\.(\d{0,2}))?$/);
    return match ? BigInt(match[1]) * 100n + BigInt((match[2] ?? '').padEnd(2, '0')) : 0n;
}

function units(value) {
    const parsed = Number(value);
    return Number.isSafeInteger(parsed) && parsed >= 0 ? BigInt(parsed) : 0n;
}

function roundedShare(amount, quantity, ordered) {
    return (2n * amount * quantity + ordered) / (2n * ordered);
}

export function estimateRefund(lines, remainingBalance = null) {
    let total = 0n;
    for (const line of lines) {
        const ordered = units(line.orderedQuantity);
        if (ordered === 0n) continue;
        const prior = units(line.alreadyReturned) < ordered ? units(line.alreadyReturned) : ordered;
        const requested = units(line.returnQuantity);
        const after = prior + requested < ordered ? prior + requested : ordered;
        if (requested === 0n) continue;
        const paid = line.paidNet == null ? cents(line.unitPrice) * ordered : cents(line.paidNet);
        const reserved = line.alreadyRefunded == null ? roundedShare(paid, prior, ordered) : cents(line.alreadyRefunded);
        const candidate = roundedShare(paid, after, ordered) - reserved;
        if (candidate > 0n) total += candidate;
    }
    if (remainingBalance != null && total > cents(remainingBalance)) total = cents(remainingBalance);
    return `${total / 100n}.${String(total % 100n).padStart(2, '0')}`;
}
