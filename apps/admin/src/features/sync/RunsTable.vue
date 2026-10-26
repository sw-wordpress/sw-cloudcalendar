<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import StatusChip from '@/components/ui/StatusChip.vue';
import { useFormat } from '@/composables/useFormat';
import type { SyncRun } from '@/types/api';

/** Sync run history (per source or across all sources). */
withDefaults(
  defineProps<{ runs: SyncRun[]; sourceNames?: Record<number, string>; showSource?: boolean }>(),
  { sourceNames: () => ({}), showSource: false },
);
const { t } = useI18n();
const fmt = useFormat();

function duration(run: SyncRun): string {
  const s = fmt.secondsBetween(run.started_at, run.finished_at);
  return s === null ? '–' : fmt.duration(s);
}
</script>

<template>
  <div class="swc-table-wrap">
    <table class="swc-table swc-runs">
      <thead>
        <tr>
          <th scope="col">{{ t('sync.started') }}</th>
          <th v-if="showSource" scope="col">{{ t('sync.source') }}</th>
          <th scope="col">{{ t('common.status') }}</th>
          <th scope="col">{{ t('sync.mode') }}</th>
          <th scope="col">{{ t('sync.changes') }}</th>
          <th scope="col">{{ t('sync.attachments') }}</th>
          <th scope="col">{{ t('sync.duration') }}</th>
          <th scope="col">{{ t('sync.error') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="run in runs" :key="run.id">
          <td :data-label="t('sync.started')">
            <span :title="fmt.dateTime(run.started_at)">{{ fmt.relative(run.started_at) }}</span>
          </td>
          <td v-if="showSource" :data-label="t('sync.source')">
            <router-link :to="`/sources/${run.source_id}`">{{
              sourceNames[run.source_id] ?? `#${run.source_id}`
            }}</router-link>
          </td>
          <td :data-label="t('common.status')">
            <StatusChip domain="run" :status="run.status" dense />
          </td>
          <td :data-label="t('sync.mode')">
            {{
              run.mode === 'full' || run.mode === 'delta' ? t(`sync.modes.${run.mode}`) : run.mode
            }}
          </td>
          <td :data-label="t('sync.changes')" class="swc-num">
            <span :title="t('sync.created')">+{{ run.created }}</span>
            <span :title="t('sync.updated')"> ~{{ run.updated }}</span>
            <span :title="t('sync.deleted')"> −{{ run.deleted }}</span>
            <span class="swc-sr-only">
              {{ t('sync.changesSr', { c: run.created, u: run.updated, d: run.deleted }) }}</span
            >
          </td>
          <td :data-label="t('sync.attachments')" class="swc-num">{{ run.attachments }}</td>
          <td :data-label="t('sync.duration')" class="swc-num">{{ duration(run) }}</td>
          <td :data-label="t('sync.error')">
            <template v-if="run.error_code || (run.status === 'failed' && run.message)">
              <code v-if="run.error_code">{{ run.error_code }}</code>
              <span v-if="run.message" class="swc-runs__msg">{{ run.message }}</span>
            </template>
            <span v-else class="swc-muted">–</span>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.swc-runs__msg {
  display: block;
  font-size: 13px;
  color: var(--swc-muted);
  max-width: 360px;
}
</style>
