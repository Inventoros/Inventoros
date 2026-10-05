/**
 * Runtime plugin UI.
 *
 * Plugins can ship a pre-built ES module (their own Vite library build, with
 * Vue left external). While a plugin is active the server publishes it under
 * /plugin-assets/{slug}/ and lists it in the shared `pluginAssets` prop; this module
 * imports each bundle once and gives it a small SDK so it can register Vue
 * components without bundling its own copy of Vue.
 *
 * A bundle either default-exports a setup function, which receives an SDK
 * scoped to its plugin:
 *
 *     export default function setup(plugin) {
 *         plugin.registerComponent('Banner', Banner);
 *         plugin.registerSlotComponent('dashboard:header', Banner);
 *     }
 *
 * or calls the same functions on `window.Inventoros` directly.
 */
import * as Vue from 'vue';
import { defineAsyncComponent, markRaw, reactive, readonly } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import CardHeader from '@/Components/ui/CardHeader.vue';
import DataTable from '@/Components/ui/DataTable.vue';
import Input from '@/Components/ui/Input.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import StatTile from '@/Components/ui/StatTile.vue';
import Checkbox from '@/Components/Checkbox.vue';
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import i18n from '@/i18n';
import { createPluginI18n, createReadonlyI18n } from './pluginI18n.js';

const components = reactive({});
const slotComponents = reactive({});
const pages = {};
const loading = new Map();

const DEFAULT_POSITION = 100;

// Components of plugins present when the app was built
// (plugins/{slug}/resources/js/Components/{Name}.vue). Source installs only.
const buildTimeComponents = import.meta.glob('../../../plugins/*/resources/js/Components/*.vue');
const asyncBuildTimeComponents = new Map();

/**
 * Shared with plugin bundles so they use the app's own copies: a second copy
 * of Inertia would not see the current page, and plugin pages should sit in
 * the same layout and use the same building blocks as core pages.
 */
const shared = Object.freeze({
    Vue,
    Inertia: Object.freeze({ Head, Link, router, useForm, usePage }),
    layouts: Object.freeze({ AppLayout: markRaw(AppLayout) }),
    ui: Object.freeze({
        Badge: markRaw(Badge),
        Button: markRaw(Button),
        Card: markRaw(Card),
        CardHeader: markRaw(CardHeader),
        PageHeader: markRaw(PageHeader),
        // Since apiVersion 2: forms, tables, figures and dialogs.
        Input: markRaw(Input),
        DataTable: markRaw(DataTable),
        StatTile: markRaw(StatTile),
        Modal: markRaw(Modal),
        Checkbox: markRaw(Checkbox),
        TextInput: markRaw(TextInput),
        InputLabel: markRaw(InputLabel),
        InputError: markRaw(InputError),
        PrimaryButton: markRaw(PrimaryButton),
        SecondaryButton: markRaw(SecondaryButton),
        DangerButton: markRaw(DangerButton),
        // The camera scanner pulls in a barcode library, so it is only
        // downloaded when a plugin renders it.
        BarcodeScanner: markRaw(defineAsyncComponent(() => import('@/Components/BarcodeScanner.vue'))),
        BarcodeScannerModal: markRaw(defineAsyncComponent(() => import('@/Components/BarcodeScannerModal.vue'))),
    }),
});

// The app locale, read-only for plugins (the language switcher owns it).
const appLocale = readonly(i18n.global.locale);

const componentKey = (plugin, name) => `${plugin}/${name}`;

function assertName(value, label) {
    if (typeof value !== 'string' || value.trim() === '') {
        throw new TypeError(`Inventoros plugin SDK: ${label} must be a non-empty string.`);
    }
}

/**
 * Provide the implementation for a component the server placed with
 * add_page_component(..., ['plugin' => $plugin, 'component' => $name]).
 */
function registerComponent(plugin, name, component) {
    assertName(plugin, 'plugin');
    assertName(name, 'component name');
    components[componentKey(plugin, name)] = markRaw(component);
}

/**
 * Render a component in a page slot, entirely from the browser.
 * `slot` is "<page>:<slot>", e.g. "dashboard:header" or "products.show:sidebar".
 */
function registerSlotComponent(slot, component, options = {}) {
    assertName(slot, 'slot');
    if (!slot.includes(':')) {
        throw new TypeError('Inventoros plugin SDK: slot must look like "<page>:<slot>", e.g. "dashboard:header".');
    }

    const entries = slotComponents[slot] ?? [];
    entries.push({
        component: markRaw(component),
        plugin: options.plugin ?? null,
        props: options.props ?? {},
        label: options.label ?? null,
        position: Number.isFinite(options.position) ? options.position : DEFAULT_POSITION,
        id: `${options.plugin ?? 'runtime'}-${entries.length}`,
    });
    slotComponents[slot] = entries;
}

/**
 * Provide the page component for a server route that renders
 * Inertia::render('Plugin::<slug>/<Page>').
 */
function registerPage(name, component) {
    assertName(name, 'page name');
    if (!name.startsWith('Plugin::')) {
        throw new TypeError('Inventoros plugin SDK: page names must start with "Plugin::", e.g. "Plugin::my-plugin/Settings".');
    }
    pages[name] = markRaw(component);
}

/**
 * The SDK handed to a bundle's default-exported setup function.
 */
function scopedTo(slug) {
    return Object.freeze({
        slug,
        ...shared,
        i18n: createPluginI18n(i18n.global, slug, appLocale),
        registerComponent: (name, component) => registerComponent(slug, name, component),
        registerSlotComponent: (slot, component, options = {}) =>
            registerSlotComponent(slot, component, { ...options, plugin: slug }),
        registerPage: (page, component) => registerPage(`Plugin::${slug}/${page}`, component),
    });
}

export function installPluginRuntime() {
    window.Inventoros = Object.freeze({
        apiVersion: 2,
        ...shared,
        i18n: createReadonlyI18n(i18n.global, appLocale),
        registerComponent,
        registerSlotComponent,
        registerPage,
        plugin: scopedTo,
    });
}

function addStylesheet(href) {
    if (document.querySelector(`link[data-inventoros-plugin-style="${CSS.escape(href)}"]`)) {
        return;
    }
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    link.dataset.inventorosPluginStyle = href;
    document.head.appendChild(link);
}

/**
 * Import every listed bundle that has not been imported yet. Safe to call on
 * every navigation; a bundle that fails to load is logged and skipped.
 */
export function loadPluginAssets(assets) {
    if (!Array.isArray(assets) || typeof window === 'undefined') {
        return Promise.resolve();
    }

    const pending = assets.map((asset) => {
        if (!asset || typeof asset.entry !== 'string') {
            return Promise.resolve();
        }

        (asset.styles ?? []).forEach(addStylesheet);

        if (!loading.has(asset.entry)) {
            loading.set(
                asset.entry,
                import(/* @vite-ignore */ asset.entry)
                    .then((module) => {
                        if (typeof module.default === 'function') {
                            return module.default(scopedTo(asset.slug));
                        }
                        return undefined;
                    })
                    .catch((error) => {
                        console.error(`[Inventoros] Plugin "${asset.slug}" UI failed to load.`, error);
                    }),
            );
        }

        return loading.get(asset.entry);
    });

    return Promise.allSettled(pending);
}

export function runtimeComponent(plugin, name) {
    return components[componentKey(plugin, name)] ?? null;
}

/**
 * The component a server placement names: a runtime bundle's registration,
 * else a component compiled into the app at build time, else null (a runtime
 * bundle that has not loaded yet; the caller re-renders once it registers).
 */
export function resolvePluginComponent(plugin, name) {
    const runtime = runtimeComponent(plugin, name);
    if (runtime) {
        return runtime;
    }

    const path = `../../../plugins/${plugin}/resources/js/Components/${name}.vue`;
    if (!buildTimeComponents[path]) {
        return null;
    }
    if (!asyncBuildTimeComponents.has(path)) {
        asyncBuildTimeComponents.set(path, defineAsyncComponent(buildTimeComponents[path]));
    }
    return asyncBuildTimeComponents.get(path);
}

export function runtimeSlotComponents(page, slot) {
    return slotComponents[`${page}:${slot}`] ?? [];
}

/**
 * Resolve a Plugin:: page registered by a runtime bundle, loading the bundles
 * listed on the page being visited first.
 */
export async function resolveRuntimePage(name, page) {
    await loadPluginAssets(page?.props?.pluginAssets);
    return pages[name] ?? null;
}

/**
 * The page identifier the server uses with add_page_component() for an
 * Inertia component name: "Products/Show" -> "products.show",
 * "PurchaseOrders/Index" -> "purchase-orders.index".
 */
export function pageIdFromComponent(component) {
    if (typeof component !== 'string') {
        return '';
    }
    return component
        .split('/')
        .map((segment) => segment.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase())
        .join('.');
}
