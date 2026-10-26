<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import CopyButton from '@/components/CopyButton.vue';
import { uid } from '@/utils/ids';
import { encryptionKeySnippet, generateEncryptionKey } from '@/utils/encryptionKey';

/** Step by step solution for the WordPress salt fallback with a ready to paste wp-config.php line. */
const open = defineModel<boolean>({ required: true });
const { t } = useI18n();
const titleId = uid('swc-kd');
// Generated in the browser for this dialog only; never sent to the server.
const snippet = ref('');

function regenerate(): void {
  snippet.value = encryptionKeySnippet(generateEncryptionKey());
}
watch(
  open,
  (value) => {
    if (value) regenerate();
  },
  { immediate: true },
);
</script>

<template>
  <q-dialog v-model="open" :aria-labelledby="titleId">
    <q-card class="swc-kd" role="dialog" :aria-labelledby="titleId">
      <q-card-section class="swc-kd__head">
        <span class="swc-kd__icon" aria-hidden="true"><q-icon name="r_key" size="24px" /></span>
        <div>
          <h2 :id="titleId" class="swc-kd__title">{{ t('diagnostics.keyDialogTitle') }}</h2>
          <p class="swc-kd__msg">{{ t('diagnostics.fallbackHint') }}</p>
        </div>
      </q-card-section>
      <q-card-section class="swc-kd__body">
        <ol class="swc-kd__steps">
          <li>{{ t('diagnostics.keyStepCopy') }}</li>
          <li>
            <i18n-t keypath="diagnostics.keyStepPaste" tag="span">
              <template #file><code>wp-config.php</code></template>
              <template #marker><code>/* That's all, stop editing! */</code></template>
            </i18n-t>
          </li>
          <li>{{ t('diagnostics.keyStepReload') }}</li>
        </ol>
        <pre class="swc-kd__snippet swc-mono" aria-label="wp-config.php">{{ snippet }}</pre>
        <div class="swc-kd__tools">
          <CopyButton
            :text="snippet"
            :label="t('diagnostics.copyKeySnippet')"
            show-label
            :flat="false"
          />
          <q-btn
            flat
            no-caps
            icon="r_refresh"
            :label="t('diagnostics.newKey')"
            @click="regenerate"
          />
        </div>
        <p class="swc-kd__notes">{{ t('diagnostics.keyNotes') }}</p>
      </q-card-section>
      <q-card-actions align="right" class="swc-kd__actions">
        <q-btn flat no-caps :label="t('common.close')" data-autofocus @click="open = false" />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<style scoped>
.swc-kd {
  width: min(640px, calc(100vw - 24px));
  max-width: none;
}
.swc-kd__head {
  display: flex;
  gap: 14px;
  align-items: flex-start;
}
.swc-kd__icon {
  display: grid;
  place-items: center;
  flex: none;
  width: 44px;
  height: 44px;
  border-radius: 12px;
  background: var(--swc-primary-bg);
  color: var(--swc-primary-text);
}
.swc-kd__title {
  font-size: 18px;
  font-weight: 700;
  line-height: 1.3;
}
.swc-kd__msg {
  margin-top: 4px;
  color: var(--swc-muted);
}
.swc-kd__body {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding-top: 0;
}
.swc-kd__steps {
  margin: 0 0 0 20px;
  padding: 0;
  list-style: decimal;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.swc-kd__snippet {
  margin: 0;
  padding: 12px 14px;
  border-radius: var(--swc-radius-sm);
  border: 1px solid var(--swc-border);
  background: var(--swc-surface-2);
  white-space: pre-wrap;
  word-break: break-all;
  user-select: all;
  font-size: 13px;
}
.swc-kd__tools {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.swc-kd__notes {
  font-size: 13px;
  color: var(--swc-muted);
}
.swc-kd__actions {
  padding: 8px 16px 16px;
}
</style>
