// The organization the current page was rendered for (the shared
// `auth.organization` prop). Every Inertia visit and axios request carries it
// in the X-Inventoros-Organization header, so a write sent from a tab that
// was opened before switching organization in another tab is refused instead
// of landing in the wrong organization (EnsureActiveOrganizationMatches).
//
// Plain module state, no Vue: importable from Node tests.

export const ORGANIZATION_HEADER = 'X-Inventoros-Organization';

let activeOrganizationId = null;

/**
 * Remember the organization from the shared `auth` prop.
 *
 * @param {{ organization?: { id?: number|null }|null }|null|undefined} auth
 */
export function applyActiveOrganization(auth) {
    const id = auth?.organization?.id;
    activeOrganizationId = Number.isInteger(id) && id > 0 ? id : null;

    if (typeof window !== 'undefined' && window.axios) {
        if (activeOrganizationId) {
            window.axios.defaults.headers.common[ORGANIZATION_HEADER] = String(activeOrganizationId);
        } else {
            delete window.axios.defaults.headers.common[ORGANIZATION_HEADER];
        }
    }
}

/**
 * The headers to add to a request, given the headers it already has.
 *
 * @param {Record<string, string>|undefined} headers
 * @returns {Record<string, string>}
 */
export function withOrganizationHeader(headers = {}) {
    if (!activeOrganizationId) return headers;

    return { ...headers, [ORGANIZATION_HEADER]: String(activeOrganizationId) };
}

export function activeOrganization() {
    return activeOrganizationId;
}
