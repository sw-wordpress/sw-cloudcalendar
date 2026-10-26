import type { Ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ApiError } from '@/api/client';

/**
 * Maps server validation codes (`data.params`, e.g. `{ "settings.url": "unsafe_url" }`) to
 * translated inline messages. Unknown codes are shown as delivered.
 */
export function useFieldErrors(error: Ref<ApiError | null>) {
  const { t, te } = useI18n();
  function message(code: string): string {
    return te(`validation.${code}`) ? t(`validation.${code}`) : code;
  }
  return {
    message,
    /** Inline message for a field (exact key or any nested key below it). */
    field(key: string): string | undefined {
      const params = error.value?.params ?? {};
      if (params[key] !== undefined) return message(params[key]);
      const nested = Object.keys(params).find((k) => k.startsWith(`${key}.`));
      return nested ? message(params[nested] ?? '') : undefined;
    },
    has(key: string): boolean {
      return error.value?.params[key] !== undefined;
    },
  };
}
