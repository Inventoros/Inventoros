// Runs with Node's built-in test runner: `npm run test:js`.
//
// The permission catalog comes from App\Enums\Permission in English. Every
// case needs a label and description in the locale files, so a new permission
// cannot show up untranslated on the role screens.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    catalogKey,
    permissionCategoryLabel,
    permissionDescription,
    permissionLabel,
    permissionSetName,
    roleDescription,
    roleName,
} from '../../resources/js/lib/permissionLabels.js';

const root = join(import.meta.dirname, '..', '..');
const read = (locale) => JSON.parse(readFileSync(join(root, 'resources', 'js', 'i18n', 'locales', `${locale}.json`), 'utf8'));
const messages = { en: read('en'), fr: read('fr') };
const lookup = (locale, path) => path.split('.').reduce((node, key) => (node == null ? undefined : node[key]), messages[locale]);

// A minimal stand-in for vue-i18n's t/te with an active locale.
const i18nFor = (active) => ({
    te: (key, locale = active) => typeof lookup(locale, key) === 'string',
    t: (key, _named, options) => lookup(options?.locale ?? active, key),
});

const enumSource = readFileSync(join(root, 'app', 'Enums', 'Permission.php'), 'utf8');
const permissionValues = [...enumSource.matchAll(/case\s+\w+\s*=\s*'([^']+)';/g)].map((m) => m[1]);
const categoryBody = /function category\(\)[\s\S]*?\n    \}/.exec(enumSource)[0];
const categories = [...new Set([...categoryBody.matchAll(/=>\s*'([^']+)',?\s*$/gm)].map((m) => m[1]))];

test('the enum is read', () => {
    assert.ok(permissionValues.length > 50);
    assert.ok(categories.includes('Reports & Data'));
});

test('every permission and category has an English label', () => {
    const i18n = i18nFor('en');
    const missing = [];
    for (const value of permissionValues) {
        for (const field of ['label', 'description']) {
            if (!i18n.te(`admin.permissionCatalog.permissions.${value}.${field}`)) missing.push(`${value}.${field}`);
        }
    }
    for (const category of categories) {
        if (!i18n.te(`admin.permissionCatalog.categories.${catalogKey(category)}`)) missing.push(`category ${category}`);
    }
    assert.deepEqual(missing, []);
});

test('labels are translated, falling back to the server text', () => {
    const fr = i18nFor('fr');
    const permission = { value: 'view_products', label: 'View Products', description: 'Can view product inventory' };
    assert.equal(permissionLabel(permission, fr), lookup('fr', 'admin.permissionCatalog.permissions.view_products.label'));
    assert.notEqual(permissionLabel(permission, fr), 'View Products');
    assert.equal(permissionDescription(permission, fr), lookup('fr', 'admin.permissionCatalog.permissions.view_products.description'));
    assert.equal(permissionCategoryLabel('Reports & Data', fr), lookup('fr', 'admin.permissionCatalog.categories.reportsData'));
    // A plugin permission the locale does not know keeps the server's label.
    assert.equal(permissionLabel({ value: 'acme_widgets', label: 'Acme Widgets' }, fr), 'Acme Widgets');
    assert.equal(permissionCategoryLabel('Acme', fr), 'Acme');
});

test('seeded roles and sets are translated only while unchanged', () => {
    const fr = i18nFor('fr');
    const seeded = { slug: 'system-manager', name: 'Manager', description: 'Can manage inventory, orders, categories, and view reports.' };
    assert.equal(roleName(seeded, fr), lookup('fr', 'admin.permissionCatalog.roles.systemManager.name'));
    assert.equal(roleDescription(seeded, fr), lookup('fr', 'admin.permissionCatalog.roles.systemManager.description'));
    // Renamed by the organization: shown as written.
    assert.equal(roleName({ ...seeded, name: 'Shift Lead' }, fr), 'Shift Lead');
    // A custom role has no seeded text.
    assert.equal(roleName({ slug: 'custom-123', name: 'Packers' }, fr), 'Packers');
    assert.equal(permissionSetName({ slug: 'warehouse-staff', name: 'Warehouse Staff' }, fr), lookup('fr', 'admin.permissionCatalog.sets.warehouseStaff.name'));
});

test('a plugin permission is translated from the plugin namespace, else shown as the server sent it', () => {
    const tree = {
        plugins: { 'cycle-counts': { permissions: { approve: { label: 'Approuver les comptages', description: 'Peut approuver' } } } },
    };
    const get = (key) => key.split('.').reduce((node, part) => (node == null ? undefined : node[part]), tree);
    const i18n = { te: (key) => typeof get(key) === 'string', t: (key) => get(key) };

    const translated = { value: 'cycle-counts.approve', label: 'Approve counts', description: 'Can approve counts' };
    assert.equal(permissionLabel(translated, i18n), 'Approuver les comptages');
    assert.equal(permissionDescription(translated, i18n), 'Peut approuver');

    const untranslated = { value: 'cycle-counts.schedule', label: 'Schedule counts', description: 'Can schedule counts' };
    assert.equal(permissionLabel(untranslated, i18n), 'Schedule counts');
    assert.equal(permissionDescription(untranslated, i18n), 'Can schedule counts');
});
