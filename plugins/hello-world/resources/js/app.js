// Hello World Plugin Entry Point
// This file exports all components that this plugin provides

import HelloWorldBanner from './Components/HelloWorldBanner.vue';

// Export components for use in the application
export {
    HelloWorldBanner,
};

// Register components globally if needed
if (window.Vue) {
    window.Vue.component('HelloWorldBanner', HelloWorldBanner);
}
