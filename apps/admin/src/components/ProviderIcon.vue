<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Provider } from '@/types/api';

/** Neutral provider badge (no brand logos): letter mark + accessible provider name. */
const props = withDefaults(defineProps<{ provider: Provider | null; size?: number }>(), {
  size: 36,
});
const { t } = useI18n();
const meta = computed(() => {
  switch (props.provider) {
    case 'google':
      return { letter: 'G', cls: 'is-google' };
    case 'microsoft':
      return { letter: 'M', cls: 'is-microsoft' };
    case 'ics':
      return { letter: 'ICS', cls: 'is-ics' };
    default:
      return { letter: '?', cls: '' };
  }
});
const label = computed(() =>
  props.provider ? t(`providers.${props.provider}`) : t('common.unknown'),
);
</script>

<template>
  <span
    class="swc-provider"
    :class="meta.cls"
    role="img"
    :aria-label="label"
    :title="label"
    :style="{ width: `${size}px`, height: `${size}px`, fontSize: `${Math.round(size / 2.6)}px` }"
    >{{ meta.letter }}</span
  >
</template>

<style scoped>
.swc-provider {
  display: inline-grid;
  place-items: center;
  flex: none;
  border-radius: 10px;
  font-weight: 800;
  letter-spacing: -0.02em;
  color: #fff;
  background: var(--swc-neutral-text);
}
.swc-provider.is-google {
  background: linear-gradient(135deg, #4285f4, #34a853);
}
.swc-provider.is-microsoft {
  background: linear-gradient(135deg, #0078d4, #50a0e6);
}
.swc-provider.is-ics {
  background: linear-gradient(135deg, #7c3aed, #db2777);
}
</style>
