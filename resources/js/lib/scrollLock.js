// Dialogs, the mobile drawer and full-table mode may overlap. Each owner
// releases only its own lock, preserving the original inline style at the end.
const owners = new Set();
let previousOverflow;

export function lockPageScroll() {
    const owner = {};
    if (owners.size === 0) previousOverflow = document.body.style.overflow;
    owners.add(owner);
    document.body.style.overflow = 'hidden';
    return () => {
        if (!owners.delete(owner)) return;
        if (owners.size === 0) document.body.style.overflow = previousOverflow;
    };
}
