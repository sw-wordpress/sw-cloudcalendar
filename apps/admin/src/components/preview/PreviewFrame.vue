<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { getConfig } from '@/config';
import { buildPreviewDocument } from '@/utils/previewDoc';
import type { PreviewDocInput } from '@/utils/previewDoc';

/**
 * Sandboxed preview of server rendered markup. `sandbox=""` grants nothing (no scripts, no
 * same-origin, no forms, no popups, no navigation of the admin page); the markup lives only in
 * `srcdoc` and never touches the admin DOM.
 */
const props = withDefaults(
  defineProps<{
    html: string;
    css?: PreviewDocInput['css'];
    title: string;
    height?: number;
    loading?: boolean;
  }>(),
  { css: '', height: 520, loading: false },
);
const { locale } = useI18n();

const srcdoc = computed(() =>
  buildPreviewDocument({
    html: props.html,
    css: props.css,
    pluginUrl: getConfig().pluginUrl,
    lang: locale.value,
  }),
);
</script>

<template>
  <div class="swc-preview" :class="{ 'is-loading': loading }" :aria-busy="loading">
    <iframe
      class="swc-preview__frame"
      sandbox=""
      referrerpolicy="no-referrer"
      loading="lazy"
      :title="title"
      :srcdoc="srcdoc"
      :style="{ height: `${height}px` }"
    />
    <q-linear-progress v-if="loading" indeterminate class="swc-preview__bar" />
  </div>
</template>

<style scoped>
.swc-preview {
  position: relative;
  border: 1px solid var(--swc-border);
  border-radius: var(--swc-radius-sm);
  overflow: hidden;
  background: #fff;
}
.swc-preview__frame {
  display: block;
  width: 100%;
  border: 0;
  background: #fff;
  transition: opacity 0.15s ease;
}
.swc-preview.is-loading .swc-preview__frame {
  opacity: 0.6;
}
.swc-preview__bar {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
}
</style>
