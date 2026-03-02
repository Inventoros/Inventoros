import { createI18n } from 'vue-i18n'

import en from './locales/en.json'
import es from './locales/es.json'
import fr from './locales/fr.json'
import de from './locales/de.json'
import ptBR from './locales/pt-BR.json'
import it from './locales/it.json'
import ja from './locales/ja.json'
import ko from './locales/ko.json'
import zhCN from './locales/zh-CN.json'
import ar from './locales/ar.json'
import ru from './locales/ru.json'
import nl from './locales/nl.json'
import tr from './locales/tr.json'
import pl from './locales/pl.json'

const savedLocale = localStorage.getItem('locale')

const i18n = createI18n({
    legacy: false,
    locale: savedLocale || document.documentElement.lang || 'en',
    fallbackLocale: 'en',
    messages: {
        en,
        es,
        fr,
        de,
        'pt-BR': ptBR,
        it,
        ja,
        ko,
        'zh-CN': zhCN,
        ar,
        ru,
        nl,
        tr,
        pl,
    },
})

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

export function setLocale(locale) {
    i18n.global.locale.value = locale
    localStorage.setItem('locale', locale)
    document.documentElement.lang = locale
    document.cookie = `locale=${locale};path=/;max-age=${60 * 60 * 24 * 365};SameSite=Lax`
}

export default i18n
