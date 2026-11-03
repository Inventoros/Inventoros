import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { baseCompile } from '@intlify/message-compiler';
import { parse as parseSfc } from '@vue/compiler-sfc';
import { createI18n } from 'vue-i18n';
import * as Vue from 'vue';
import { renderToString } from '@vue/server-renderer';
import { createPluginI18n } from '../../resources/js/plugins/pluginI18n.js';

const root = join(import.meta.dirname, '../..');
const supported = readdirSync(join(root, 'resources/js/i18n/locales')).filter((file) => file.endsWith('.json')).sort();
const read = (path) => readFileSync(join(root, path), 'utf8');
const placeholders = (message) => [...message.matchAll(/\{\s*(\w+)\s*\}/g)].map((match) => match[1]).sort();
const flatten = (object, prefix = '') => Object.fromEntries(Object.entries(object).flatMap(([key, value]) => {
    const path = prefix ? `${prefix}.${key}` : key;
    return typeof value === 'object' ? Object.entries(flatten(value, path)) : [[path, value]];
}));
const source = {};

for (const slug of ['hello-world', 'tax-billing']) {
    const path = `plugins/${slug}/ui/src/locales`;
    source[slug] = flatten(JSON.parse(read(`${path}/en.json`)));
    test(`${slug} covers every supported locale with valid messages and matching interpolation`, () => {
        assert.deepEqual(readdirSync(join(root, path)).sort(), supported);
        for (const file of supported) {
            const messages = flatten(JSON.parse(read(`${path}/${file}`)));
            assert.deepEqual(Object.keys(messages).sort(), Object.keys(source[slug]).sort(), file);
            for (const [key, value] of Object.entries(messages)) {
                assert.equal(typeof value, 'string', `${file}:${key}`);
                assert.ok(value.trim(), `${file}:${key} must not be empty`);
                assert.deepEqual(placeholders(value), placeholders(source[slug][key]), `${file}:${key}`);
                assert.equal(value.split('|').length, source[slug][key].split('|').length, `${file}:${key}`);
                const errors = [];
                baseCompile(value, { onError: (error) => errors.push(error.message) });
                assert.deepEqual(errors, [], `${file}:${key}`);
            }
        }
    });

    test(`${slug} Vue messages all have source keys and no remaining English interface text`, () => {
        const directory = join(root, `plugins/${slug}/ui/src`);
        const missing = [];
        for (const file of readdirSync(directory).filter((name) => name.endsWith('.vue'))) {
            const text = readFileSync(join(directory, file), 'utf8');
            for (const match of text.matchAll(/\bt\(\s*['"]([^'"]+)['"]/g)) {
                if (!(match[1] in source[slug])) missing.push(`${file}:${match[1]}`);
            }
            // ISO examples and legal tax abbreviations are technical values.
            const { descriptor } = parseSfc(text);
            const visit = (node) => {
                const content = node.type === 2 ? node.content.trim() : '';
                if (/[A-Za-z]{2}/.test(content) && !['SGST', 'UTGST'].includes(content)) {
                    missing.push(`${file}: untranslated text ${content}`);
                }
                for (const child of node.children ?? []) visit(child);
            };
            visit(descriptor.template.ast);
        }
        assert.deepEqual(missing, []);
    });
}

test('tax backend catalogs cover all supported locales, keys and domain messages', () => {
    const directory = 'plugins/tax-billing/lang';
    const locales = readdirSync(join(root, directory)).sort();
    assert.deepEqual(locales, supported.map((file) => file.replace('.json', '')));
    const keyPattern = /^\s*'([^']+)'\s*=>/gm;
    const keys = (text) => [...text.matchAll(keyPattern)].map((match) => match[1]).sort();
    const en = read(`${directory}/en/messages.php`);
    for (const locale of locales) {
        assert.deepEqual(keys(read(`${directory}/${locale}/messages.php`)), keys(en), locale);
    }
    for (const name of ['Controller', 'Invoices', 'Profile']) {
        const php = read(`plugins/tax-billing/src/${name}.php`);
        for (const match of php.matchAll(/__\('tax-billing::messages\.([^']+)'\)/g)) {
            assert.ok(keys(en).includes(match[1]), `${name}:${match[1]}`);
        }
    }
});

test('shipped plugin bundles register all locales and update rendered UI when the app language changes', async () => {
    const block = Vue.defineComponent({ setup: (_, { slots }) => () => Vue.h('div', slots.default?.()) });
    const header = Vue.defineComponent({ props: ['title', 'description'], setup: (props) => () => Vue.h('header', [props.title, props.description]) });
    const composer = createI18n({ legacy: false, locale: 'en', fallbackLocale: 'en', messages: {} }).global;
    const previousWindow = globalThis.window;
    globalThis.window = { Inventoros: {
        Vue, layouts: { AppLayout: block }, ui: { Button: block, Card: block, PageHeader: header },
        Inertia: { Head: block, Link: block, useForm: (data) => ({ ...data, errors: {} }) },
    } };
    try {
        for (const slug of ['hello-world', 'tax-billing']) {
            const components = {};
            const pages = {};
            const { default: setup } = await import(`../../plugins/${slug}/dist/plugin.js`);
            setup({
                i18n: createPluginI18n(composer, slug, composer.locale),
                registerComponent: (name, component) => { components[name] = component; },
                registerPage: (name, component) => { pages[name] = component; },
            });
            for (const file of supported) {
                const code = file.replace('.json', '');
                composer.locale.value = code;
                const messages = JSON.parse(read(`plugins/${slug}/ui/src/locales/${file}`));
                assert.equal(composer.t(`plugins.${slug}.title`), messages.title);
                const component = slug === 'hello-world' ? components.HelloWorldWidget : components.InvoiceLink;
                const html = await renderToString(Vue.createSSRApp(component, { productCount: 1234, url: '/invoice' }));
                assert.ok(html.includes(slug === 'hello-world' ? messages.openPage : messages.taxInvoice), `${slug}:${code}`);
                if (slug === 'hello-world') assert.ok(html.includes(new Intl.NumberFormat(code).format(1234)), code);
            }
        }
    } finally {
        if (previousWindow === undefined) delete globalThis.window;
        else globalThis.window = previousWindow;
    }
});
