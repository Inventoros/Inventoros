import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import * as vueExports from 'vue';

const HOST_VUE = '\0inventoros-host-vue';

function useHostVue() {
    const names = Object.keys(vueExports).filter(
        (name) => name !== 'default' && /^[A-Za-z_$][\w$]*$/.test(name),
    );

    return {
        name: 'inventoros-host-vue',
        enforce: 'pre',
        resolveId(source) {
            return source === 'vue' ? HOST_VUE : null;
        },
        load(id) {
            if (id !== HOST_VUE) {
                return null;
            }
            return [
                'const Vue = window.Inventoros.Vue;',
                'export default Vue;',
                `export const { ${names.join(', ')} } = Vue;`,
            ].join('\n');
        },
    };
}

const here = (path) => fileURLToPath(new URL(path, import.meta.url));

export default defineConfig({
    root: here('.'),
    publicDir: false,
    plugins: [useHostVue(), vue()],
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
    },
    // Keep the app's PostCSS/Tailwind setup out of the plugin build.
    css: {
        postcss: {},
    },
    build: {
        outDir: here('../dist'),
        emptyOutDir: true,
        lib: {
            entry: here('./src/main.js'),
            formats: ['es'],
            fileName: () => 'plugin.js',
            cssFileName: 'plugin',
        },
    },
});
