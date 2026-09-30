import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from 'ziggy-js';
import i18n, { applyServerLocale } from './i18n';
import { applyRegionalProp } from './lib/formatSettings';
import { installPluginRuntime, loadPluginAssets, resolveRuntimePage } from './plugins/runtime';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// window.Inventoros: Vue plus the plugin SDK, for pre-built plugin bundles
// that are imported at runtime (see ./plugins/runtime.js).
installPluginRuntime();

// Glob patterns for pages
const pages = import.meta.glob('./Pages/**/*.vue');
const pluginPages = import.meta.glob('../../plugins/*/resources/js/Pages/**/*.vue');

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: async (name, page) => {
        // Check if this is a plugin page (format: Plugin::PluginName/PagePath)
        if (name.startsWith('Plugin::')) {
            // A page registered by a runtime plugin bundle.
            const runtimePage = await resolveRuntimePage(name, page);
            if (runtimePage) {
                return runtimePage;
            }

            const [, pluginPath] = name.split('::');
            const [pluginName, ...pagePath] = pluginPath.split('/');
            const pageName = pagePath.join('/');
            const path = `../../plugins/${pluginName}/resources/js/Pages/${pageName}.vue`;

            if (pluginPages[path]) {
                return pluginPages[path]();
            }

            // Fallback: try to resolve from main pages
            console.warn(`Plugin page not found: ${path}, falling back to main pages`);
        }

        // Default: resolve from main pages
        return resolvePageComponent(
            `./Pages/${name}.vue`,
            pages,
        );
    },
    setup({ el, App, props, plugin }) {
        // Keep vue-i18n on the locale the server resolved, including after a
        // visit that changed it (saving the language preference).
        applyServerLocale(props.initialPage?.props?.locale);
        router.on('navigate', (event) => applyServerLocale(event.detail.page.props.locale));

        // Money and dates follow the organization's regional settings.
        applyRegionalProp(props.initialPage?.props?.regional);
        router.on('navigate', (event) => applyRegionalProp(event.detail.page.props.regional));

        // Import the UI bundles of active plugins, and any newly activated one.
        loadPluginAssets(props.initialPage?.props?.pluginAssets);
        router.on('navigate', (event) => loadPluginAssets(event.detail.page.props.pluginAssets));

        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(i18n)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
