// Entry point of the Hello World runtime UI bundle.
//
// Inventoros imports the built file (dist/plugin.js) in the browser and calls
// the default export with an SDK scoped to this plugin. "vue" is not bundled:
// the build maps it to window.Inventoros.Vue, the app's own copy.
import HelloWorldBanner from './HelloWorldBanner.vue';

export default function setup(plugin) {
    // Implements the placement made in Plugin.php:
    // add_page_component('dashboard', 'header', ['plugin' => 'hello-world', 'component' => 'HelloWorldBanner'])
    plugin.registerComponent('HelloWorldBanner', HelloWorldBanner);
}
