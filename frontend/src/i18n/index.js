import { createI18n } from 'vue-i18n';
import en from './en.json';
import ptBR from './pt-BR.json';

const i18n = createI18n({
  legacy: false,
  locale: localStorage.getItem('user_locale') || 'en',
  fallbackLocale: 'en',
  messages: {
    en,
    'pt-BR': ptBR
  }
});

export default i18n;
