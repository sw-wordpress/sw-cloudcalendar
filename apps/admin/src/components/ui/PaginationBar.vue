<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useFormat } from '@/composables/useFormat';

defineProps<{ page: number; pages: number; total: number }>();
const emit = defineEmits<{ (e: 'update:page', value: number): void }>();
const { t } = useI18n();
const fmt = useFormat();
</script>

<template>
  <nav v-if="pages > 1 || total > 0" class="swc-pager" :aria-label="t('common.pagination')">
    <span class="swc-pager__info swc-num" aria-live="polite">
      {{ t('common.totalItems', { n: fmt.number(total) }, total) }}
    </span>
    <div v-if="pages > 1" class="swc-pager__controls">
      <q-btn
        flat
        round
        dense
        icon="r_chevron_left"
        :disable="page <= 1"
        :aria-label="t('common.previous')"
        @click="emit('update:page', page - 1)"
      />
      <span class="swc-num">{{ t('common.pageOf', { page, pages }) }}</span>
      <q-btn
        flat
        round
        dense
        icon="r_chevron_right"
        :disable="page >= pages"
        :aria-label="t('common.next')"
        @click="emit('update:page', page + 1)"
      />
    </div>
  </nav>
</template>

<style scoped>
.swc-pager {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  padding: 10px 14px;
  font-size: 13px;
  color: var(--swc-muted);
}
.swc-pager__controls {
  display: flex;
  align-items: center;
  gap: 8px;
}
</style>
