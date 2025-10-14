import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { readdirSync, existsSync } from 'fs';
import { resolve } from 'path';

// Dynamically discover plugin entry points
function getPluginEntryPoints() {
    const pluginsDir = resolve(__dirname, 'plugins');
    const entryPoints = [];

    if (existsSync(pluginsDir)) {
        const plugins = readdirSync(pluginsDir, { withFileTypes: true })
            .filter(dirent => dirent.isDirectory())
            .map(dirent => dirent.name);

        plugins.forEach(plugin => {
            const pluginJsPath = resolve(pluginsDir, plugin, 'resources/js/app.js');
            if (existsSync(pluginJsPath)) {
                entryPoints.push(pluginJsPath);
            }
        });
    }

    return entryPoints;
}

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.js', ...getPluginEntryPoints()],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
            '@plugins': resolve(__dirname, 'plugins'),
        },
    },
});
