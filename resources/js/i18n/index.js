import { createI18n } from 'vue-i18n'
import { applyFormatSettings } from '../lib/formatSettings.js'
import { createLocaleLoader } from './localeLoader.js'

import en from './locales/en.json'

// English stays available even when a language chunk fails to download. Other
// core catalogs are fetched only when used, including before the first mount.
const localeModules = import.meta.glob(['./locales/*.json', '!./locales/en.json'])
const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en },
})

const applyDocumentLocale = (locale) => {
    document.documentElement.lang = locale
    document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr'
}

export const availableLocales = [
    { code: 'en', name: 'English', flag: '\uD83C\uDDFA\uD83C\uDDF8' },
    { code: 'es', name: 'Espa\u00f1ol', flag: '\uD83C\uDDEA\uD83C\uDDF8' },
    { code: 'fr', name: 'Fran\u00e7ais', flag: '\uD83C\uDDEB\uD83C\uDDF7' },
    { code: 'de', name: 'Deutsch', flag: '\uD83C\uDDE9\uD83C\uDDEA' },
    { code: 'pt-BR', name: 'Portugu\u00eas (BR)', flag: '\uD83C\uDDE7\uD83C\uDDF7' },
    { code: 'it', name: 'Italiano', flag: '\uD83C\uDDEE\uD83C\uDDF9' },
    { code: 'ja', name: '\u65E5\u672C\u8A9E', flag: '\uD83C\uDDEF\uD83C\uDDF5' },
    { code: 'ko', name: '\uD55C\uAD6D\uC5B4', flag: '\uD83C\uDDF0\uD83C\uDDF7' },
    { code: 'zh-CN', name: '\u4E2D\u6587', flag: '\uD83C\uDDE8\uD83C\uDDF3' },
    { code: 'ar', name: '\u0627\u0644\u0639\u0631\u0628\u064A\u0629', flag: '\uD83C\uDDF8\uD83C\uDDE6' },
    { code: 'ru', name: '\u0420\u0443\u0441\u0441\u043A\u0438\u0439', flag: '\uD83C\uDDF7\uD83C\uDDFA' },
    { code: 'nl', name: 'Nederlands', flag: '\uD83C\uDDF3\uD83C\uDDF1' },
    { code: 'tr', name: 'T\u00fcrk\u00e7e', flag: '\uD83C\uDDF9\uD83C\uDDF7' },
    { code: 'pl', name: 'Polski', flag: '\uD83C\uDDF5\uD83C\uDDF1' },
]

const isSupported = (locale) => availableLocales.some((item) => item.code === locale)
const savedLocale = () => {
    try {
        return localStorage.getItem('locale')
    } catch {
        // Storage may be unavailable in a restricted browser context.
        return null
    }
}

// The server's saved-user/cookie/default resolution wins. Storage is only a
// fallback when the server did not render a language into the document.
const requestedLocale = document.documentElement.lang || savedLocale() || 'en'
const initialLocale = isSupported(requestedLocale) ? requestedLocale : 'en'

const loader = createLocaleLoader({
    loaded: ['en'],
    loaders: Object.fromEntries(availableLocales
        .filter(({ code }) => code !== 'en')
        .map(({ code }) => [code, localeModules[`./locales/${code}.json`]])),
    mergeMessages: (locale, messages) => i18n.global.mergeLocaleMessage(locale, messages),
    applyLocale: (locale, { persist = false } = {}) => {
        applyFormatSettings({ locale })
        applyDocumentLocale(locale)
        i18n.global.locale.value = locale
        if (persist) {
            try {
                localStorage.setItem('locale', locale)
            } catch {
                // The cookie/server preference still persists the selection.
            }
            document.cookie = `locale=${locale};path=/;max-age=${60 * 60 * 24 * 365};SameSite=Lax`
        }
    },
})

/** Preload before resolving an Inertia page; plugin-only messages do not count. */
export const loadLocaleMessages = (locale) => loader.load(locale)

/** Wait for the first catalog so a saved language never flashes English. */
export async function initializeLocale(locale = initialLocale) {
    const resolved = isSupported(locale) ? locale : initialLocale
    try {
        return await applyServerLocale(resolved)
    } catch {
        // A failed language download must not leave the application blank.
        return applyServerLocale('en')
    }
}

/**
 * Apply a locale resolved by the server (the shared `locale` prop) without
 * rewriting the cookie. Unknown codes are ignored so a bad value cannot blank
 * the UI.
 */
export function applyServerLocale(locale) {
    return loader.select(locale)
}

export function setLocale(locale) {
    return loader.select(locale, { persist: true })
}

export default i18n
