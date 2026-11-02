export function containTabFocus(event, container) {
    if (event.key !== 'Tab' || !container) return;
    const controls = [...container.querySelectorAll('a[href], button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])')]
        .filter(element => element.getClientRects().length && !element.closest('[inert]') && getComputedStyle(element).visibility !== 'hidden');
    const first = controls[0];
    const last = controls.at(-1);
    if (!first) {
        event.preventDefault();
        container.focus();
    } else if (event.shiftKey && (document.activeElement === first || !container.contains(document.activeElement))) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && (document.activeElement === last || !container.contains(document.activeElement))) {
        event.preventDefault();
        first.focus();
    }
}
