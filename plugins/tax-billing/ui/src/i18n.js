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
export const formatMoney = (value, currency) => new Intl.NumberFormat(i18n.locale.value, {
    style: 'currency', currency,
}).format(value);
// Invoice dates are calendar dates. UTC avoids shifting them to a different
// day when the browser and issuing organization have different time zones.
export const formatDate = (value) => new Intl.DateTimeFormat(i18n.locale.value, {
    dateStyle: 'medium', timeZone: 'UTC',
}).format(new Date(`${value}T00:00:00Z`));
