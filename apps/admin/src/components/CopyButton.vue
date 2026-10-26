<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useNotify } from '@/composables/useNotify';

/** Copies a text to the clipboard; the label names what is copied for screen readers. */
const props = withDefaults(
  defineProps<{ text: string; label: string; flat?: boolean; showLabel?: boolean }>(),
  { flat: true, showLabel: false },
);
const { t } = useI18n();
const notify = useNotify();
const done = ref(false);

async function copy(): Promise<void> {
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(props.text);
    } else {
      const area = document.createElement('textarea');
      area.value = props.text;
      area.setAttribute('readonly', '');
      area.style.position = 'fixed';
      area.style.opacity = '0';
      document.body.appendChild(area);
      area.select();
      document.execCommand('copy');
      area.remove();
    }
    done.value = true;
    notify.success(t('common.copied'));
    window.setTimeout(() => (done.value = false), 2000);
  } catch {
    notify.warning(t('common.copyFailed'));
  }
}
</script>

<template>
  <q-btn
    :flat="flat"
    :outline="!flat"
    no-caps
    dense
    :round="!showLabel"
    :icon="done ? 'r_check' : 'r_content_copy'"
    :label="showLabel ? label : undefined"
    :aria-label="label"
    @click="copy"
  >
    <q-tooltip v-if="!showLabel">{{ label }}</q-tooltip>
  </q-btn>
</template>
