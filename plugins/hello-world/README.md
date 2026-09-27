# Hello World plugin

An example Inventoros plugin. It does nothing useful: while active it shows a
dismissible banner at the top of the dashboard, a small dashboard widget and a
"Hello" tab on product pages for users who can view products, and a page at
`/hello-world` linked from the sidebar. It writes a line to the log on activation, deactivation and deletion.
Delete it whenever you like.

It is also the reference for how a plugin is put together, including a UI that
works on installs that never run npm (the cPanel release, or a plugin uploaded
as a ZIP).

## Layout

```text
hello-world/
  plugin.json          Manifest: name, version, requires, requires_php, ui bundle
  Plugin.php           Loaded on every request while active: hooks and UI placement
  hooks/
    activate.php       Runs once on activation (a throw keeps the plugin inactive)
    deactivate.php     Runs once on deactivation
    uninstall.php      Runs once when the plugin is deleted
  ui/
    src/main.js        Bundle entry: registers the banner, the widget and the page
    src/HelloWorldBanner.vue
    src/HelloWorldWidget.vue
    src/HelloWorldPage.vue
    src/HelloWorldTab.vue
    vite.config.js     Library build; maps "vue" to the app's copy
    package.json       For building outside the Inventoros repository
  dist/
    plugin.js          Pre-built bundle, published to public/plugins/hello-world/
    plugin.css
```

## How the banner gets on the page

1. `Plugin.php` places it: `add_page_component('dashboard', 'header', ['plugin' => 'hello-world', 'component' => 'HelloWorldBanner'])`.
2. On activation Inventoros copies `dist/` to `public/plugins/hello-world/` and lists `plugin.js` in the `pluginAssets` page prop.
3. The browser imports `plugin.js`, whose default export calls `plugin.registerComponent('HelloWorldBanner', ...)`.
4. The dashboard's `PluginSlot` renders the registered component.

The widget works the same way through `register_dashboard_widget()` (with a
`view_products` permission, so its data is only computed for users who can see
it). The page is `register_page('hello-world.index', 'Plugin::hello-world/Hello', ...)`
on the server and `plugin.registerPage('Hello', ...)` in the bundle; it uses the
app's layout from `window.Inventoros.layouts.AppLayout`.

## Rebuilding the UI

Inside the Inventoros repository:

```bash
npm run build:plugin:hello-world
```

Outside it:

```bash
cd ui
npm install
npm run build
```

Commit or ship the rebuilt `dist/` with the plugin; Inventoros never builds it.

## Packaging

Each Inventoros release attaches `hello-world-plugin.zip`. To make one yourself,
zip the `hello-world` folder (without `ui/node_modules`) so the archive has a
single top-level `hello-world/` directory, then upload it from the Plugins page.
