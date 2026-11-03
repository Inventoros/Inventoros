// Register messages through the host SDK so switching the app language also
// updates plugin pages, placements and navigation without reloading a bundle.
const catalogs = import.meta.glob('./locales/*.json', { eager: true, import: 'default' });
let i18n;

export function installMessages(plugin) {
    i18n = plugin.i18n;
    for (const [path, messages] of Object.entries(catalogs)) {
        i18n.addMessages(path.split('/').pop().replace('.json', ''), messages);
    }
}

export const t = (key, params = {}) => i18n.t(key, params);
export const formatNumber = (value) => new Intl.NumberFormat(i18n.locale.value).format(value);
