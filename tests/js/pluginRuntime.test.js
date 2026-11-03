// Runs with Node's built-in test runner: `npm run test:js`.
//
// The runtime plugin SDK (resources/js/plugins/runtime.js): its API version,
// the components it shares with plugin bundles, and plugin translations,
// which may only live under plugins.{slug}.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { computed, reactive } from 'vue';
import { createI18n } from 'vue-i18n';
import { createPluginI18n, createReadonlyI18n, pluginLabel, sanitizeMessages } from '../../resources/js/plugins/pluginI18n.js';

const root = join(import.meta.dirname, '..', '..');
const runtime = readFileSync(join(root, 'resources', 'js', 'plugins', 'runtime.js'), 'utf8');

// A small stand-in for vue-i18n's global composer: deep-merging messages,
// fallback to English, {named} interpolation.
function fakeComposer(active = 'fr') {
    const messages = { en: { common: { save: 'Save' } }, fr: {} };
    const merge = (target, source) => {
        for (const [key, value] of Object.entries(source)) {
            if (value && typeof value === 'object') {
                target[key] = merge(target[key] ?? {}, value);
            } else {
                target[key] = value;
            }
        }
        return target;
    };
    const get = (locale, key) => key.split('.').reduce((node, part) => (node == null ? undefined : node[part]), messages[locale]);
    const locale = { value: active };

    return {
        messages,
        locale,
        fallbackLocale: { value: 'en' },
        mergeLocaleMessage: (code, tree) => {
            messages[code] = merge(messages[code] ?? {}, tree);
        },
        getLocaleMessage: (code) => messages[code] ?? {},
        setLocaleMessage: (code, tree) => { messages[code] = tree; },
        te: (key, code = locale.value) => typeof get(code, key) === 'string',
        t: (key, params = {}) => {
            const text = get(locale.value, key) ?? get('en', key) ?? key;
            return text.replace(/\{(\w+)\}/g, (m, name) => (name in params ? String(params[name]) : m));
        },
    };
}

test('the SDK reports API version 2', () => {
    assert.match(runtime, /apiVersion:\s*2\b/);
});

test('the SDK shares the core building blocks with plugin bundles', () => {
    const uiBlock = /ui:\s*Object\.freeze\(\{([\s\S]*?)\}\)/.exec(runtime)?.[1] ?? '';
    const shared = [...uiBlock.matchAll(/^\s*(\w+):/gm)].map((m) => m[1]);

    for (const name of [
        'Badge', 'Button', 'Card', 'CardHeader', 'PageHeader',
        'Input', 'DataTable', 'StatTile', 'Modal', 'Checkbox', 'TextInput', 'InputLabel', 'InputError',
        'PrimaryButton', 'SecondaryButton', 'DangerButton', 'BarcodeScanner', 'BarcodeScannerModal',
    ]) {
        assert.ok(shared.includes(name), `ui.${name} should be shared`);
    }
});

test('the barcode scanner is shared lazily so it does not grow the main bundle', () => {
    assert.doesNotMatch(runtime, /^import\s+BarcodeScanner/m);
    assert.match(runtime, /defineAsyncComponent\(\(\)\s*=>\s*import\('@\/Components\/BarcodeScannerModal\.vue'\)\)/);
});

test('plugin messages are added under plugins.{slug} and read relative to it', () => {
    const composer = fakeComposer('fr');
    const i18n = createPluginI18n(composer, 'cycle-counts', composer.locale);

    i18n.addMessages('en', { settings: { title: 'Cycle counts', count: '{n} counts' } });
    i18n.addMessages('fr', { settings: { title: 'Comptages' } });

    assert.equal(composer.messages.fr.plugins['cycle-counts'].settings.title, 'Comptages');
    assert.equal(i18n.t('settings.title'), 'Comptages');
    assert.equal(i18n.t('settings.count', { n: 3 }), '3 counts', 'falls back to English');
    assert.equal(i18n.te('settings.count'), true);
    assert.equal(i18n.te('settings.missing'), false);
    assert.equal(i18n.locale, composer.locale);
});

test('a plugin cannot write outside its own namespace', () => {
    const composer = fakeComposer('en');
    const i18n = createPluginI18n(composer, 'evil', composer.locale);

    i18n.addMessages('en', { common: { save: 'Hacked' } });
    i18n.addMessages('en', JSON.parse('{"__proto__": {"polluted": "yes"}, "ok": "fine"}'));

    assert.equal(composer.messages.en.common.save, 'Save');
    assert.equal(composer.messages.en.plugins.evil.common.save, 'Hacked');
    assert.equal(composer.messages.en.plugins.evil.ok, 'fine');
    assert.equal({}.polluted, undefined);
    assert.throws(() => i18n.addMessages('', { a: 'b' }), TypeError);
    assert.throws(() => i18n.addMessages('en', 'not an object'), TypeError);
    assert.throws(() => i18n.t(''), TypeError);
});

test('messages keep only strings and nested objects', () => {
    assert.deepEqual(sanitizeMessages({ a: 'x', b: 1, c: { d: 'y', e: null }, f: ['z'] }), { a: 'x', c: { d: 'y' } });
});

test('the global i18n is read-only', () => {
    const composer = fakeComposer('en');
    const global = createReadonlyI18n(composer, composer.locale);

    assert.equal(global.t('common.save'), 'Save');
    assert.equal(global.te('common.save'), true);
    assert.equal('addMessages' in global, false);
    assert.ok(Object.isFrozen(global));
});

test('a plugin label key resolves from the plugin messages and falls back to the plain label', () => {
    const composer = fakeComposer('fr');
    createPluginI18n(composer, 'cycle-counts', composer.locale).addMessages('en', { nav: { counts: 'Cycle counts' } });
    const te = (key, code) => composer.te(key, code);

    // Not translated into French: the English message, through the fallback locale.
    assert.equal(pluginLabel(composer.t, te, 'plugins.cycle-counts.nav.counts', 'Counts'), 'Cycle counts');

    createPluginI18n(composer, 'cycle-counts', composer.locale).addMessages('fr', { nav: { counts: 'Inventaires tournants' } });
    assert.equal(pluginLabel(composer.t, te, 'plugins.cycle-counts.nav.counts', 'Counts'), 'Inventaires tournants');

    // The bundle has not loaded (or has no such key): the plain label.
    assert.equal(pluginLabel(composer.t, te, 'plugins.cycle-counts.nav.missing', 'Counts'), 'Counts');
    // No key, or a key outside plugins.*: the plain label; never a core string.
    assert.equal(pluginLabel(composer.t, te, null, 'Docs'), 'Docs');
    assert.equal(pluginLabel(composer.t, te, 'common.save', 'Docs'), 'Docs');
    assert.equal(pluginLabel(composer.t, te, undefined, undefined), '');
});

test('cached plugin labels update when a bundle registers messages after the first render', () => {
    // vue-i18n uses a shallow message ref in Node. A reactive catalog gives
    // this real composer the same nested dependency tracking as its browser ref.
    const i18n = createI18n({
        legacy: false,
        locale: 'ar',
        fallbackLocale: 'en',
        missingWarn: false,
        fallbackWarn: false,
        messages: reactive({
            en: { common: { save: 'Save' } },
            ar: { common: { save: 'حفظ' } },
            fr: { common: { save: 'Enregistrer' } },
        }),
    });
    const composer = i18n.global;
    const plugin = createPluginI18n(composer, 'cycle-counts', composer.locale);
    const otherPlugin = createPluginI18n(composer, 'other-plugin', composer.locale);
    otherPlugin.addMessages('ar', { title: 'إضافة أخرى' });
    const label = computed(() => pluginLabel(composer.t, composer.te, 'plugins.cycle-counts.nav.counts', 'Counts'));
    const pluginTitle = computed(() => plugin.t('nav.counts'));
    const translated = computed(() => plugin.te('nav.counts'));

    // Evaluate before either the fallback or active plugin catalog arrives,
    // just as the app shell does while a plugin bundle is downloading.
    assert.equal(label.value, 'Counts');
    assert.equal(pluginTitle.value, 'plugins.cycle-counts.nav.counts');
    assert.equal(translated.value, false);

    plugin.addMessages('en', { nav: { counts: 'Cycle counts' } });
    assert.equal(label.value, 'Cycle counts');
    assert.equal(pluginTitle.value, 'Cycle counts');
    assert.equal(translated.value, true);

    plugin.addMessages('ar', { nav: { counts: 'الجرد الدوري' } });
    assert.equal(label.value, 'الجرد الدوري');
    assert.equal(pluginTitle.value, 'الجرد الدوري');
    assert.equal(composer.t('common.save'), 'حفظ', 'core messages survive plugin registration');
    assert.equal(otherPlugin.t('title'), 'إضافة أخرى', 'other plugins retain their messages');

    // Additional message batches retain the first batch, and switching to
    // a locale whose plugin catalog arrives later invalidates the same label.
    plugin.addMessages('ar', { settings: { title: 'الإعدادات' } });
    assert.equal(label.value, 'الجرد الدوري');
    assert.equal(plugin.t('settings.title'), 'الإعدادات');
    composer.locale.value = 'fr';
    assert.equal(label.value, 'Cycle counts');
    plugin.addMessages('fr', { nav: { counts: 'Inventaires tournants' } });
    assert.equal(label.value, 'Inventaires tournants');
    assert.equal(composer.t('common.save'), 'Enregistrer');
    i18n.dispose();
});
