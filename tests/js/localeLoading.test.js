import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createI18n } from 'vue-i18n';
import { createLocaleLoader } from '../../resources/js/i18n/localeLoader.js';
import { createPluginI18n } from '../../resources/js/plugins/pluginI18n.js';
import { applyFormatSettings, formatLocale, defaultCurrency, orgDateFormat } from '../../resources/js/lib/formatSettings.js';

const deferred = () => {
    let resolve;
    let reject;
    const promise = new Promise((done, fail) => { resolve = done; reject = fail; });
    return { promise, resolve, reject };
};

function setup(loaders = {}) {
    const i18n = createI18n({
        legacy: false,
        locale: 'en',
        fallbackLocale: 'en',
        missingWarn: false,
        fallbackWarn: false,
        messages: { en: { common: { save: 'Save', fallback: 'English fallback' } } },
    });
    const applied = [];
    const loader = createLocaleLoader({
        loaders,
        loaded: ['en'],
        mergeMessages: (code, messages) => i18n.global.mergeLocaleMessage(code, messages),
        applyLocale: (code, options) => {
            i18n.global.locale.value = code;
            applied.push({ code, ...options });
        },
    });
    return { ...loader, composer: i18n.global, applied };
}

test('English remains immediately available without requesting a catalog', async () => {
    const app = setup();
    const selected = app.select('en', { persist: true });
    assert.deepEqual(app.applied, [{ code: 'en', persist: true }]);
    assert.equal(await selected, true);
    assert.equal(app.composer.t('common.save'), 'Save');
});

test('preloading leaves the active language unchanged, deduplicates and caches the download', async () => {
    let downloads = 0;
    const chunk = deferred();
    const app = setup({ fr: () => { downloads++; return chunk.promise; } });
    const first = app.load('fr');
    const second = app.load('fr');
    assert.equal(first, second);
    await Promise.resolve();
    assert.equal(downloads, 1);
    assert.equal(app.composer.locale.value, 'en');
    chunk.resolve({ default: { common: { save: 'Enregistrer' } } });
    assert.equal(await first, true);
    assert.equal(await app.load('fr'), true);
    assert.equal(downloads, 1);
    assert.deepEqual(app.applied, []);
    const selected = app.select('fr');
    assert.equal(app.composer.locale.value, 'fr');
    assert.equal(await selected, true);
    assert.equal(app.composer.t('common.save'), 'Enregistrer');
    assert.equal(app.composer.t('common.fallback'), 'English fallback');
});

test('a selection does not change or persist language before its messages are ready', async () => {
    const chunk = deferred();
    const app = setup({ ar: () => chunk.promise });
    const selected = app.select('ar', { persist: true });
    assert.equal(app.composer.locale.value, 'en');
    assert.deepEqual(app.applied, []);
    chunk.resolve({ default: { common: { save: 'Arabic save' } } });
    assert.equal(await selected, true);
    assert.equal(app.composer.locale.value, 'ar');
    assert.deepEqual(app.applied, [{ code: 'ar', persist: true }]);
});

test('a failed language download retains the current language and can be retried', async () => {
    let downloads = 0;
    const app = setup({ fr: () => {
        downloads++;
        if (downloads === 1) throw new Error('offline');
        return { default: { common: { save: 'Enregistrer' } } };
    } });
    await assert.rejects(app.select('fr', { persist: true }), /offline/);
    assert.equal(app.composer.locale.value, 'en');
    assert.deepEqual(app.applied, []);
    assert.equal(await app.select('fr', { persist: true }), true);
    assert.equal(downloads, 2);
    assert.deepEqual(app.applied, [{ code: 'fr', persist: true }]);
});

test('the last requested language wins when downloads finish in reverse order', async () => {
    const fr = deferred();
    const ar = deferred();
    const app = setup({ fr: () => fr.promise, ar: () => ar.promise });
    const first = app.select('fr', { persist: true });
    const latest = app.select('ar', { persist: true });
    ar.resolve({ default: { common: { save: 'Arabic save' } } });
    assert.equal(await latest, true);
    fr.resolve({ default: { common: { save: 'Enregistrer' } } });
    assert.equal(await first, false);
    assert.equal(app.composer.locale.value, 'ar');
    assert.deepEqual(app.applied, [{ code: 'ar', persist: true }]);
    assert.equal(await app.load('fr'), true);
    assert.equal(app.composer.locale.value, 'ar');
});

test('choosing the current language cancels a slower selection without another download', async () => {
    const chunk = deferred();
    const app = setup({ fr: () => chunk.promise });
    const pending = app.select('fr', { persist: true });
    assert.equal(await app.select('en', { persist: true }), true);
    chunk.resolve({ default: { common: { save: 'Enregistrer' } } });
    assert.equal(await pending, false);
    assert.equal(app.composer.locale.value, 'en');
    assert.deepEqual(app.applied, [{ code: 'en', persist: true }]);
});

test('an earlier server response cannot cancel a newer user selection while its catalog downloads', async () => {
    const chunk = deferred();
    const app = setup({ fr: () => chunk.promise });
    const selected = app.select('fr', { persist: true });
    assert.equal(await app.select('en'), false);
    assert.deepEqual(app.applied, []);
    chunk.resolve({ default: { common: { save: 'Enregistrer' } } });
    assert.equal(await selected, true);
    assert.equal(app.composer.locale.value, 'fr');
    assert.deepEqual(app.applied, [{ code: 'fr', persist: true }]);
    // Later server navigation resumes the usual authoritative synchronization.
    assert.equal(await app.select('en'), true);
    assert.equal(app.composer.locale.value, 'en');
});

test('a failed user download releases server synchronization for later navigation', async () => {
    const app = setup({ fr: async () => { throw new Error('offline'); } });
    await assert.rejects(app.select('fr', { persist: true }), /offline/);
    assert.equal(await app.select('en'), true);
    assert.deepEqual(app.applied, [{ code: 'en' }]);
});

test('an older failed request cannot revert a newer successful selection', async () => {
    const fr = deferred();
    const app = setup({ fr: () => fr.promise, ar: async () => ({ common: { save: 'Arabic save' } }) });
    const rejected = assert.rejects(app.select('fr', { persist: true }), /offline/);
    assert.equal(await app.select('ar', { persist: true }), true);
    fr.reject(new Error('offline'));
    await rejected;
    assert.equal(app.composer.locale.value, 'ar');
    assert.deepEqual(app.applied, [{ code: 'ar', persist: true }]);
});

test('unsupported codes and prototype keys are ignored without cancelling a valid selection', async () => {
    const chunk = deferred();
    const app = setup({ fr: () => chunk.promise });
    const selected = app.select('fr');
    for (const code of ['xx', '__proto__', 'constructor', '', undefined, null]) {
        assert.equal(await app.load(code), false);
        assert.equal(await app.select(code), false);
    }
    chunk.resolve({ default: { common: { save: 'Enregistrer' } } });
    assert.equal(await selected, true);
    assert.equal(app.composer.locale.value, 'fr');
});

test('core loading preserves plugin messages registered before and during the request', async () => {
    const chunk = deferred();
    const app = setup({ fr: () => chunk.promise });
    const plugin = createPluginI18n(app.composer, 'test-plugin', app.composer.locale);
    plugin.addMessages('fr', { before: 'Registered before loading' });
    plugin.addMessages('en', { fallback: 'Plugin English fallback' });
    const selected = app.select('fr');
    plugin.addMessages('fr', { during: 'Registered during loading' });
    assert.equal(app.composer.locale.value, 'en');
    chunk.resolve({ default: { common: { save: 'Enregistrer' } } });
    assert.equal(await selected, true);
    assert.equal(app.composer.t('common.save'), 'Enregistrer');
    assert.equal(plugin.t('before'), 'Registered before loading');
    assert.equal(plugin.t('during'), 'Registered during loading');
    assert.equal(plugin.t('fallback'), 'Plugin English fallback');
    plugin.addMessages('fr', { after: 'Registered after loading' });
    await app.load('fr');
    assert.equal(plugin.t('after'), 'Registered after loading');
});

test('UI language changes preserve the organization currency and date settings', async () => {
    applyFormatSettings({ locale: 'en', currency: 'CAD', dateFormat: 'd/m/Y' });
    const loader = createLocaleLoader({
        loaded: ['en'],
        loaders: { ar: async () => ({ default: {} }) },
        mergeMessages: () => {},
        applyLocale: (locale) => applyFormatSettings({ locale }),
    });
    assert.equal(await loader.select('ar'), true);
    assert.equal(formatLocale(), 'ar');
    assert.equal(defaultCurrency(), 'CAD');
    assert.equal(orgDateFormat(), 'd/m/Y');
    applyFormatSettings({ locale: 'en', currency: 'USD', dateFormat: null });
});
