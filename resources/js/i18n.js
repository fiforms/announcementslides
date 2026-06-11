import { createI18n } from 'vue-i18n'
import en from './locales/en.json'
import es from './locales/es.json'

const supportedLocales = ['en', 'es']

// Detect browser locale, falling back to 'en'
const browserLocale = navigator.language?.split('-')[0] ?? 'en'
const locale = supportedLocales.includes(browserLocale) ? browserLocale : 'en'

export default createI18n({
    legacy: false,
    locale,
    fallbackLocale: 'en',
    messages: { en, es },
})
