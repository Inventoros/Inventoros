// Runs with Node's built-in test runner: `npm run test:js`.
//
// Count + word messages ("1 units", "1 row(s)") must be plural messages and
// be called with the count as the plural choice, so one reads "1 unit".
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { createI18n } from 'vue-i18n';

const root = join(import.meta.dirname, '..', '..', 'resources', 'js');
const en = JSON.parse(readFileSync(join(root, 'i18n', 'locales', 'en.json'), 'utf8'));
const { t } = createI18n({ legacy: false, locale: 'en', messages: { en } }).global;

const flatten = (obj, prefix = '', out = {}) => {
    for (const [key, value] of Object.entries(obj)) {
        const path = prefix ? `${prefix}.${key}` : key;
        if (value !== null && typeof value === 'object') flatten(value, path, out);
        else out[path] = value;
    }
    return out;
};
const messages = flatten(en);

// Messages where a count sits next to a word that changes with it.
const PLURAL_KEYS = {
    'reports.inventoryTurnover.periodDays': ['Over 1 day', 'Over 3 days', 'n'],
    'reports.inventoryTurnover.estimatedCost': ['1 unit was sold', '3 units were sold'],
    'reports.profitMargin.estimatedCost': ['1 unit was sold', '3 units were sold'],
    'reports.profitMargin.missingCost': ['1 unit sold has', '3 units sold have'],
    'reports.abcAnalysis.productCount': ['1 product', '3 products'],
    'importExport.result.errorRows': ['1 row with errors', '3 rows with errors'],
    'importExport.result.warningRows': ['1 row with warnings', '3 rows with warnings'],
    'settings.apiTokens.abilitiesCount': ['1 permission', '3 permissions'],
    'admin.users.edit.customRolesSelected': ['1 custom role selected', '3 custom roles selected'],
    'admin.roles.usersCount': ['1 user', '3 users'],
    'admin.roles.permissionsCount': ['1 permission', '3 permissions'],
    'admin.roles.create.totalPermissions': ['1 permission in total', '3 permissions in total'],
    'admin.roles.edit.permissionsSelected': ['1 permission selected', '3 permissions selected'],
    'components.barcodeScanner.productsHere': ['1 product here', '3 products here'],
    'components.activityTimeline.moreChanges': ['+1 more change', '+3 more changes'],
    'components.imageUploader.canAddMore': ['add 1 more image', 'add 3 more images'],
    'warehouses.users.selected': ['1 user selected', '3 users selected'],
    'workOrders.actions.completeConfirm': ['1 unit will be added', '3 units will be added'],
    'shipping.estDays': ['Est. 1 day', 'Est. 3 days'],
    'salesUnits.sold': ['1 unit sold', '3 units sold'],
    'admin.roles.create.permissionBreakdown': ['from 1 set)', 'from 3 sets)', 'sets'],
    'workOrders.create.materialsNeeded': ['produce 1 unit of', 'produce 3 units of'],
};

test('count messages read singular for one and plural for more', () => {
    for (const [key, [one, many, param = 'count']] of Object.entries(PLURAL_KEYS)) {
        assert.ok(t(key, { [param]: 1 }, 1).includes(one), `${key} with 1: ${t(key, { [param]: 1 }, 1)}`);
        assert.ok(t(key, { [param]: 3 }, 3).includes(many), `${key} with 3: ${t(key, { [param]: 3 }, 3)}`);
    }
});

test('no English message hedges with (s)', () => {
    const hedged = Object.entries(messages).filter(([, v]) => typeof v === 'string' && /\w\((s|es)\)/.test(v)).map(([k]) => k);
    assert.deepEqual(hedged, []);
});

function sourceFiles(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) return name === 'i18n' ? [] : sourceFiles(path);
        return name.endsWith('.vue') || name.endsWith('.js') ? [path] : [];
    });
}

// Every plural message in en.json ("one | many"), not only the list above.
const PLURAL_MESSAGE_KEYS = Object.entries(messages).filter(([, v]) => typeof v === 'string' && v.includes('|')).map(([k]) => k);

test('every call of a plural count message passes the count as the plural choice', () => {
    const offenders = [];
    for (const file of sourceFiles(root)) {
        const source = readFileSync(file, 'utf8');
        for (const key of PLURAL_MESSAGE_KEYS) {
            const opener = `t('${key}'`;
            let at = source.indexOf(opener);
            while (at !== -1) {
                // Read the call's arguments up to its balanced closing paren.
                let depth = 0;
                let end = at + 1;
                for (; end < source.length; end++) {
                    if (source[end] === '(') depth++;
                    else if (source[end] === ')' && --depth === 0) break;
                }
                const args = source.slice(at + opener.length, end);
                // Top-level arguments after the key: a plural call has a
                // number (t(key, n)) or a named object and a number.
                let level = 0;
                let commas = 0;
                let firstArg = '';
                for (const ch of args) {
                    if ('({['.includes(ch)) level++;
                    else if (')}]'.includes(ch)) level--;
                    else if (ch === ',' && level === 0) { commas++; continue; }
                    if (commas === 1) firstArg += ch;
                }
                const hasChoice = commas >= 2 || (commas === 1 && !firstArg.trim().startsWith('{'));
                if (!hasChoice) offenders.push(`${file.slice(root.length)}: ${source.slice(at, end + 1)}`);
                at = source.indexOf(opener, end);
            }
        }
    }
    assert.deepEqual(offenders, []);
});
