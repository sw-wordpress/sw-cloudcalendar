<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { uid } from '@/utils/ids';

const open = defineModel<boolean>({ required: true });
withDefaults(
  defineProps<{ title: string; subtitle?: string; width?: number; persistent?: boolean }>(),
  { subtitle: undefined, width: 720, persistent: false },
);
const emit = defineEmits<{ (e: 'hide'): void }>();
const { t } = useI18n();
const titleId = uid('swc-sheet');
</script>

<template>
  <q-dialog
    v-model="open"
    position="right"
    full-height
    :persistent="persistent"
    :maximized="false"
    @hide="emit('hide')"
  >
    <q-card
      class="swc-sheet"
      :style="{ '--sheet-w': `${width}px` }"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="titleId"
    >
      <header class="swc-sheet__head">
        <div class="swc-sheet__titles">
          <h2 :id="titleId" class="swc-sheet__title swc-truncate">{{ title }}</h2>
          <p v-if="subtitle" class="swc-sheet__subtitle swc-truncate">{{ subtitle }}</p>
        </div>
        <q-btn
          flat
          round
          dense
          icon="r_close"
          :aria-label="t('common.close')"
          @click="open = false"
        />
      </header>
      <div class="swc-sheet__body"><slot /></div>
      <footer v-if="$slots.footer" class="swc-sheet__foot"><slot name="footer" /></footer>
    </q-card>
  </q-dialog>
</template>

<style scoped>
.swc-sheet {
  width: min(var(--sheet-w), 100vw);
  max-width: 100vw;
  height: 100%;
  display: flex;
  flex-direction: column;
  border-radius: var(--swc-radius) 0 0 var(--swc-radius);
}
.swc-sheet__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 18px 22px;
  border-bottom: 1px solid var(--swc-border);
  background: var(--swc-gradient-soft);
}
.swc-sheet__titles {
  min-width: 0;
}
.swc-sheet__title {
  font-size: 19px;
  font-weight: 700;
  letter-spacing: -0.01em;
}
.swc-sheet__subtitle {
  font-size: 13px;
  color: var(--swc-muted);
}
.swc-sheet__body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 8px 22px 22px;
}
.swc-sheet__foot {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  flex-wrap: wrap;
  padding: 14px 22px;
  border-top: 1px solid var(--swc-border);
  background: var(--swc-surface);
}
@media (max-width: 600px) {
  .swc-sheet {
    border-radius: 0;
  }
  .swc-sheet__head,
  .swc-sheet__foot {
    padding-inline: 16px;
  }
  .swc-sheet__body {
    padding-inline: 16px;
  }
}
</style>
