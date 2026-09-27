// Entry point of the Hello World runtime UI bundle.
//
// Inventoros imports the built file (dist/plugin.js) in the browser and calls
// the default export with an SDK scoped to this plugin. "vue" is not bundled:
// the build maps it to window.Inventoros.Vue, the app's own copy.
import HelloWorldBanner from './HelloWorldBanner.vue';
import HelloWorldPage from './HelloWorldPage.vue';
import HelloWorldWidget from './HelloWorldWidget.vue';

export default function setup(plugin) {
    // Implements add_page_component('dashboard', 'header', [... 'component' => 'HelloWorldBanner']).
    plugin.registerComponent('HelloWorldBanner', HelloWorldBanner);

    // Implements register_dashboard_widget([... 'component' => 'HelloWorldWidget']).
    plugin.registerComponent('HelloWorldWidget', HelloWorldWidget);

    // Supplies Inertia::render('Plugin::hello-world/Hello') for register_page('hello-world.index', ...).
    plugin.registerPage('Hello', HelloWorldPage);
}
