import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from 'ziggy-js';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Glob patterns for pages
const pages = import.meta.glob('./Pages/**/*.vue');
const pluginPages = import.meta.glob('../../plugins/*/resources/js/Pages/**/*.vue');

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => {
        // Check if this is a plugin page (format: Plugin::PluginName/PagePath)
        if (name.startsWith('Plugin::')) {
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
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
