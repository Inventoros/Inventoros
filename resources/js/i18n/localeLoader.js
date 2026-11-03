/**
 * Load a core catalog once, then activate only the latest locale request.
 * Kept separate from browser/Vite imports so the race and retry behavior can
 * be exercised with the same vue-i18n composer used by the application.
 */
export function createLocaleLoader({ loaders, loaded = [], mergeMessages, applyLocale }) {
    const ready = new Set(loaded);
    const pending = new Map();
    let selection = 0;
    let pendingUserSelection = 0;

    const supports = (locale) => ready.has(locale) || Object.hasOwn(loaders, locale);

    function load(locale) {
        if (!supports(locale)) return Promise.resolve(false);
        if (ready.has(locale)) return Promise.resolve(true);
        if (pending.has(locale)) return pending.get(locale);

        const request = Promise.resolve()
            .then(() => loaders[locale]())
            .then((module) => {
                // Plugins can register messages before this request finishes.
                // Merge the core catalog instead of replacing that locale tree.
                mergeMessages(locale, module.default ?? module);
                ready.add(locale);
                return true;
            })
            .finally(() => pending.delete(locale));
        pending.set(locale, request);
        return request;
    }

    function select(locale, options = {}) {
        if (!supports(locale)) return Promise.resolve(false);
        // A response to the previous preference save can arrive while the
        // user's next choice downloads. It must not cancel that newer choice.
        if (!options.persist && pendingUserSelection) return Promise.resolve(false);
        const request = ++selection;
        const activate = () => {
            if (request !== selection) return false;
            applyLocale(locale, options);
            return true;
        };

        // An already-loaded choice still applies synchronously. Selecting the
        // current language also cancels an earlier, slower language request.
        if (ready.has(locale)) {
            pendingUserSelection = 0;
            return Promise.resolve(activate());
        }
        pendingUserSelection = options.persist ? request : 0;
        return load(locale).then(activate).finally(() => {
            if (pendingUserSelection === request) pendingUserSelection = 0;
        });
    }

    return { load, select };
}
