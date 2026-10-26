import type { QuasarLanguage } from 'quasar';
import de from 'quasar/lang/de';
import enUS from 'quasar/lang/en-US';
import type { UiLocale } from '@/config';

const packs: Record<UiLocale, QuasarLanguage> = { en: enUS, de };

/** Quasar's own component labels (dialogs, pagination, date pickers) follow the UI language. */
export default function quasarLang(locale: UiLocale): QuasarLanguage {
  return packs[locale];
}
