// Labels for the permission catalog (App\Enums\Permission), its categories,
// and the seeded system roles and permission set templates. The server sends
// them in English; the locale files carry a translation per permission value
// and per seeded slug.
//
// Roles and permission sets are records an admin can rename, so their seeded
// name and description are translated only while they still read exactly as
// seeded; anything the organization wrote itself is shown as written.

const PREFIX = 'admin.permissionCatalog';

/** 'Reports & Data' -> 'reportsData', 'system-administrator' -> 'systemAdministrator' */
export function catalogKey(text) {
    const words = String(text ?? '')
        .toLowerCase()
        .split(/[^a-z0-9]+/)
        .filter(Boolean);

    return words.map((w, i) => (i === 0 ? w : w[0].toUpperCase() + w.slice(1))).join('');
}

const translated = (key, fallback, { t, te } = {}) => (t && te && te(key) ? t(key) : fallback);

// The English a seeded record started with, read from the en locale.
const seededText = (key, { t, te } = {}) => (t && te && te(key, 'en') ? t(key, {}, { locale: 'en' }) : undefined);

export const permissionLabel = (permission, i18n) =>
    translated(`${PREFIX}.permissions.${permission?.value}.label`, permission?.label ?? '', i18n);

export const permissionDescription = (permission, i18n) =>
    translated(`${PREFIX}.permissions.${permission?.value}.description`, permission?.description ?? '', i18n);

export const permissionCategoryLabel = (category, i18n) =>
    translated(`${PREFIX}.categories.${catalogKey(category)}`, category ?? '', i18n);

function seededField(kind, record, field, i18n) {
    const value = record?.[field] ?? '';
    if (!record?.slug) return value;
    const key = `${PREFIX}.${kind}.${catalogKey(record.slug)}.${field}`;

    return seededText(key, i18n) === value ? translated(key, value, i18n) : value;
}

export const roleName = (role, i18n) => seededField('roles', role, 'name', i18n);
export const roleDescription = (role, i18n) => seededField('roles', role, 'description', i18n);
export const permissionSetName = (set, i18n) => seededField('sets', set, 'name', i18n);
export const permissionSetDescription = (set, i18n) => seededField('sets', set, 'description', i18n);
